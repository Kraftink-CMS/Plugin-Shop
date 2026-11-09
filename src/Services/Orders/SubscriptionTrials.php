<?php

namespace KraftInk\Plugins\Shop\Services\Orders;

use App\Models\Accounts\User;
use App\Kraft\Games\GameRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use KraftInk\Plugins\Shop\Models\Catalog\Product;
use KraftInk\Plugins\Shop\Models\Orders\Order;
use KraftInk\Plugins\Shop\Models\Orders\OrderItem;
use KraftInk\Plugins\Shop\Models\Orders\Subscription;
use KraftInk\Plugins\Shop\Models\Orders\SubscriptionTrial;
use KraftInk\Plugins\Shop\Services\Payments\GatewayRegistry;
use KraftInk\Plugins\Shop\Services\Roles\RoleAssignments;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SubscriptionTrials
{
    public function eligible(User $buyer, User $recipient, Product $product): bool
    {
        return $product->trial_days > 0 &&
            $this->reversible($product) &&
            !$this->used($buyer->id, $recipient->id, $this->family($product, $product->currency))->exists();
    }

    public function reversible(Product|OrderItem $product): bool
    {
        return $product->billing_interval &&
            $product->kind === 'product' &&
            ($product->stock ?? null) === null &&
            !$product->wallet_credit_minor &&
            !$product->upgrade_group &&
            !($product->uses_codes ?? false) &&
            !array_filter(json_decode($product->commands ?: '[]', true) ?: []) &&
            empty($product->variables) &&
            !($product->custom_price ?? false) &&
            ($product->role_id || $product->download_path);
    }

    public function reserve(Order $order, OrderItem $item): void
    {
        if (!$item->trial_days) {
            return;
        }
        if (
            !(
                $order->recurring &&
                $order->user_id &&
                in_array('trials', app(GatewayRegistry::class)->get($order->provider)->capabilities(), true)
            )
        ) {
            throw ValidationException::withMessages(['trial_requested' => __('shop::subscriptions.trial_unsupported')]);
        }
        $product = $item->product;
        if (!$product || !$this->reversible($product) || $item->trial_days !== $product->trial_days) {
            throw ValidationException::withMessages(['trial_requested' => __('shop::subscriptions.trial_unavailable')]);
        }
        $recipient = $item->recipient_id ?? $order->user_id;
        User::query()
            ->whereIn('id', array_unique([$order->user_id, $recipient]))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $family = $this->family($item, $order->currency);
        SubscriptionTrial::query()
            ->where('family', $family)
            ->whereNull('started_at')
            ->whereHas(
                'order',
                fn (Builder $query) => $query->where(
                    fn (Builder $query) => $query
                        ->whereIn('status', ['cancelled', 'expired'])
                        ->orWhere(
                            fn (Builder $query) => $query->where('auto_expire', true)->where('expires_at', '<=', now()),
                        ),
                ),
            )
            ->delete();
        if ($this->used($order->user_id, $recipient, $family)->where('order_id', '!=', $order->id)->exists()) {
            throw ValidationException::withMessages(['trial_requested' => __('shop::subscriptions.trial_used')]);
        }
        SubscriptionTrial::query()->firstOrCreate(
            ['order_id' => $order->id],
            [
                'user_id' => $order->user_id,
                'recipient_id' => $recipient,
                'family' => $family,
            ],
        );
    }

    public function activate(Subscription $subscription, array $remote): void
    {
        $trial = SubscriptionTrial::query()->where('order_id', $subscription->order_id)->first();
        if (!$trial) {
            return;
        }
        $start = $remote['trial_start'] ?? null;
        $end = $remote['trial_end'] ?? null;
        $item = OrderItem::query()->where('order_id', $subscription->order_id)->firstOrFail();
        if (
            !(
                $subscription->status === 'trialing' &&
                is_int($start) &&
                is_int($end) &&
                $end > $start &&
                $end <= $start + $item->trial_days * 86400 &&
                $item->trial_days > 0 &&
                $end > now()->timestamp
            )
        ) {
            throw new HttpException(422);
        }
        DB::transaction(function () use ($subscription, $trial, $item, $start, $end) {
            $order = Order::query()->whereKey($subscription->order_id)->lockForUpdate()->firstOrFail();
            $trial = SubscriptionTrial::query()->whereKey($trial->id)->lockForUpdate()->firstOrFail();
            if (
                $trial->started_at &&
                ($trial->subscription_id !== $subscription->id || $trial->ends_at->timestamp !== $end)
            ) {
                throw new HttpException(422);
            }
            if (
                $order->status !== 'pending' ||
                $order->financial_hold ||
                $order->pricing_hold ||
                $order->giftcard_hold ||
                $order->released_at
            ) {
                throw new HttpException(422);
            }
            foreach (
                User::query()
                    ->whereIn('id', [$order->user_id, $trial->recipient_id])
                    ->get() as $user
            ) {
                if ($user->isBanned() || $user->anonymized_at || $user->import_locked) {
                    throw new HttpException(403);
                }
            }
            $trial->update([
                'subscription_id' => $subscription->id,
                'started_at' => Carbon::createFromTimestamp($start),
                'ends_at' => Carbon::createFromTimestamp($end),
            ]);
            $subscription->setRelation('trial', $trial);
            $order->update(['auto_expire' => false]);
            app(RoleAssignments::class)->grant($order, $item, Carbon::createFromTimestamp($end)->toDateTimeString());
        }, 3);
    }

    private function used(int $buyer, int $recipient, string $family): Builder
    {
        return SubscriptionTrial::query()
            ->where('family', $family)
            ->where(
                fn (Builder $query) => $query
                    ->whereIn('user_id', [$buyer, $recipient])
                    ->orWhereIn('recipient_id', [$buyer, $recipient]),
            )
            ->where(
                fn (Builder $query) => $query
                    ->whereNotNull('started_at')
                    ->orWhereHas(
                        'order',
                        fn (Builder $query) => $query
                            ->where('status', 'pending')
                            ->where(
                                fn (Builder $query) => $query
                                    ->where('auto_expire', false)
                                    ->orWhere('expires_at', '>', now()),
                            ),
                    ),
            );
    }

    private function family(object $item, string $currency): string
    {
        return hash(
            'sha256',
            json_encode(
                [
                    $item->game ?? app(GameRegistry::class)->activeId(),
                    $currency,
                    $item->subscription_group ?: 'product:'.($item->product_id ?? $item->id),
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
    }
}
