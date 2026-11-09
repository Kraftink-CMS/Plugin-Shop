<?php

namespace KraftInk\Plugins\Shop\Services\Orders;

use App\Kraft\Auth\PermissionAccess;
use App\Kraft\System\OperationLock;
use App\Models\Accounts\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use KraftInk\Plugins\Shop\Models\Catalog\Product;
use KraftInk\Plugins\Shop\Models\Orders\OrderItem;
use KraftInk\Plugins\Shop\Models\Orders\Order;
use KraftInk\Plugins\Shop\Models\Orders\RoleGrant;
use KraftInk\Plugins\Shop\Models\Orders\Subscription;
use KraftInk\Plugins\Shop\Models\Orders\SubscriptionChange;
use KraftInk\Plugins\Shop\Models\Orders\SubscriptionPayment;
use KraftInk\Plugins\Shop\Services\Pricing\Allocation;
use KraftInk\Plugins\Shop\Services\Pricing\UpgradeLedger;
use KraftInk\Plugins\Shop\Services\Payments\GatewayRegistry;
use KraftInk\Plugins\Shop\Services\Payments\StripeService;
use KraftInk\Plugins\Shop\Services\Catalog\PurchaseLimits;
use KraftInk\Plugins\Shop\Services\Catalog\Catalog;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SubscriptionPlans
{
    public function available(Subscription $subscription): bool
    {
        return $subscription->provider === 'stripe' &&
            $subscription->status === 'active' &&
            !$subscription->cancel_at_period_end &&
            $subscription->paid_until?->isFuture() &&
            $subscription
                ->payments()
                ->where('period_end', '>', now())
                ->whereHas('order', fn ($order) => $order->usable())
                ->exists() &&
            !empty($this->snapshot($subscription)['subscription_group']) &&
            app(SubscriptionTrials::class)->reversible(
                new OrderItem()->newFromBuilder($this->snapshot($subscription)),
            ) &&
            !$subscription
                ->payments()
                ->where('period_end', '>', now())
                ->whereDoesntHave('order', fn ($order) => $order->usable())
                ->exists() &&
            !SubscriptionChange::query()
                ->where('subscription_id', $subscription->id)
                ->whereIn('status', ['submitting', 'pending', 'scheduled'])
                ->exists();
    }

    public function choices(Subscription $subscription, User $buyer): object
    {
        $current = $this->snapshot($subscription);
        $recipient = User::query()->find($current['recipient_id'] ?? $buyer->id);

        return Product::query()
            ->visibleTo($buyer)
            ->where('subscription_group', $current['subscription_group'] ?? null)
            ->visibleTo($recipient)
            ->where('currency', $subscription->order->currency)
            ->where('billing_interval', $current['billing_interval'])
            ->where('id', '!=', $subscription->product_id)
            ->orderBy('price_minor')
            ->get()
            ->filter(fn (Product $product) => app(SubscriptionTrials::class)->reversible($product))
            ->map(fn (Product $product) => app(Catalog::class)->localize($product));
    }

    public function quote(Subscription $subscription, int $productId, User $buyer): SubscriptionChange
    {
        return app(OperationLock::class)->shared(function () use ($subscription, $productId, $buyer) {
            return DB::transaction(function () use ($subscription, $productId, $buyer) {
                $subscription = Subscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
                $this->authorize($subscription, $buyer);
                $product = $this->choices($subscription, $buyer)->firstWhere('id', $productId);
                if (!$product || $product->price_minor === ($this->snapshot($subscription)['unit_minor'] ?? null)) {
                    throw ValidationException::withMessages(['product_id' => __('shop::subscriptions.unavailable')]);
                }
                $this->limits($subscription, $product);
                $key = (string) Str::uuid();
                $payments = app(StripeService::class);
                $remote = $payments->subscription($subscription->provider_reference);
                $item = $payments->subscriptionItem($remote);
                $end = $item['current_period_end'] ?? ($remote['current_period_end'] ?? null);
                if (
                    !(
                        is_int($end) &&
                        $end === $subscription->paid_until->timestamp &&
                        $end > now()->timestamp &&
                        ($remote['status'] ?? '') === 'active' &&
                        empty($remote['pending_update']) &&
                        empty($remote['schedule']) &&
                        empty($remote['cancel_at_period_end']) &&
                        ($remote['collection_method'] ?? '') === 'charge_automatically'
                    )
                ) {
                    throw ValidationException::withMessages(['product_id' => __('shop::subscriptions.unavailable')]);
                }
                $date = now()->timestamp;
                $price = $payments->subscriptionPrice($product, $key);
                $amount = 0;
                $funding = [];
                $effective = $end;
                if ($product->price_minor > $this->snapshot($subscription)['unit_minor']) {
                    $invoice = $payments->previewSubscription($remote, $price, $date);
                    if (
                        !(
                            is_int($invoice['amount_due'] ?? null) &&
                            $invoice['amount_due'] >= 0 &&
                            strtoupper($invoice['currency'] ?? '') === $subscription->order->currency &&
                            empty($invoice['starting_balance']) &&
                            empty($invoice['ending_balance']) &&
                            empty($invoice['total_discount_amounts']) &&
                            empty($invoice['total_taxes']) &&
                            empty($invoice['has_more'])
                        )
                    ) {
                        throw new HttpException(422);
                    }
                    $amount = $invoice['amount_due'];
                    $effective = $amount > 0 ? $date : $end;
                    $credit = 0;
                    foreach ($invoice['lines']['data'] ?? [] as $line) {
                        if (($line['amount'] ?? 0) < 0) {
                            if (
                                !is_int($line['amount']) ||
                                !(
                                    $line['proration'] ??
                                    ($line['parent']['subscription_item_details']['proration'] ?? false)
                                )
                            ) {
                                throw new HttpException(422);
                            }
                            $credit -= $line['amount'];
                        }
                    }
                    if ($credit > 0) {
                        $orders = $subscription
                            ->payments()
                            ->where('period_end', $subscription->paid_until)
                            ->pluck('order_id');
                        $items = OrderItem::query()->whereIn('order_id', $orders)->pluck('id')->all();
                        $net = app(UpgradeLedger::class)->netAmounts($items);
                        if ($credit > array_sum($net)) {
                            throw ValidationException::withMessages([
                                'subscription' => __('shop::subscriptions.funding_unavailable'),
                            ]);
                        }
                        $funding = Allocation::proportional($credit, $net);
                    }
                }
                $snapshot = $this->snapshot($subscription);
                $previous = $snapshot;
                unset($previous['previous_plan'], $previous['access_ends_at']);
                $previous['provider_price'] = is_array($item['price']) ? $item['price']['id'] : $item['price'];
                foreach (
                    ['role_id', 'download_path', 'name', 'category_id', 'subscription_group', 'billing_interval'] as $field
                ) {
                    $snapshot[$field] = $product->{$field};
                }
                $snapshot = array_replace($snapshot, [
                    'product_id' => $product->id,
                    'unit_minor' => $product->price_minor,
                    'promotion_minor' => 0,
                    'paid_minor' => $product->price_minor,
                    'trial_days' => 0,
                    'pricing_snapshot' => null,
                    'discount_snapshot' => null,
                    'provider_price' => $price,
                    'previous_plan' => $previous,
                ]);

                return SubscriptionChange::query()->create([
                    'id' => $key,
                    'subscription_id' => $subscription->id,
                    'product_id' => $product->id,
                    'status' => 'quoted',
                    'amount_minor' => $amount,
                    'provider_price' => $price,
                    'snapshot' => $snapshot,
                    'funding_snapshot' => $funding,
                    'proration_at' => Carbon::createFromTimestamp($date),
                    'effective_at' => Carbon::createFromTimestamp($effective),
                    'expires_at' => now()->addMinutes(10),
                ]);
            }, 3);
        });
    }

    public function confirm(SubscriptionChange $change, User $buyer): ?string
    {
        return app(OperationLock::class)->shared(function () use ($change, $buyer) {
            $change = DB::transaction(function () use ($change, $buyer) {
                $subscription = Subscription::query()
                    ->whereKey($change->subscription_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $change = SubscriptionChange::query()->whereKey($change->id)->lockForUpdate()->firstOrFail();
                $this->authorizeOwner($subscription, $buyer);
                if (in_array($change->status, ['pending', 'scheduled', 'applied'], true)) {
                    return $change;
                }
                if ($change->status === 'quoted') {
                    $this->authorize($subscription, $buyer);
                    if (
                        $change->expires_at->isPast() ||
                        !$this->choices($subscription, $buyer)->contains('id', $change->product_id)
                    ) {
                        throw ValidationException::withMessages(['change' => __('shop::subscriptions.quote_expired')]);
                    }
                    $this->limits($subscription, Product::query()->findOrFail($change->product_id));
                    $this->lockFunding($change);
                    if (!$this->funded($change)) {
                        throw ValidationException::withMessages([
                            'change' => __('shop::subscriptions.funding_unavailable'),
                        ]);
                    }
                    $change->update(['status' => 'submitting']);
                } elseif ($change->status !== 'submitting') {
                    throw new HttpException(409);
                }

                return $change;
            }, 3);
            if ($change->status === 'pending') {
                return $this->resume($change, $buyer);
            }
            if ($change->status !== 'submitting') {
                return null;
            }
            $subscription = $change->subscription;
            $payments = app(StripeService::class);
            $remote = $payments->subscription($subscription->provider_reference);
            if ($change->amount_minor === 0) {
                if ($change->provider_reference) {
                    $schedule = $payments->subscriptionSchedule($change->provider_reference);
                } else {
                    try {
                        $schedule = $payments->createSubscriptionSchedule($remote, $change->id);
                    } catch (ApiErrorException $exception) {
                        if ($exception->getHttpStatus() !== 400 || empty($remote['schedule'])) {
                            throw $exception;
                        }
                        $reference = is_array($remote['schedule']) ? $remote['schedule']['id'] : $remote['schedule'];
                        $schedule = $payments->subscriptionSchedule($reference);
                        if (($schedule['metadata']['kraftink_change'] ?? null) !== $change->id) {
                            throw ValidationException::withMessages([
                                'change' => __('shop::subscriptions.schedule_uncertain'),
                            ]);
                        }
                    }
                }
                $change->update(['provider_reference' => $schedule['id']]);
                $scheduled = $payments->scheduleSubscription(
                    $remote,
                    $schedule,
                    $change->provider_price,
                    $change->effective_at->timestamp,
                    $change->snapshot['billing_interval'],
                    $change->id,
                );
                SubscriptionChange::query()
                    ->whereKey($change->id)
                    ->where('status', 'submitting')
                    ->update(['status' => 'scheduled', 'provider_reference' => $scheduled['id']]);

                return null;
            }
            $remote = $payments->changeSubscription(
                $remote,
                $change->provider_price,
                $change->proration_at->timestamp,
                $change->id,
            );
            $invoice = $remote['latest_invoice'] ?? null;
            if (
                !(
                    is_array($invoice) &&
                    is_string($invoice['id'] ?? null) &&
                    ($invoice['amount_due'] ?? null) === $change->amount_minor &&
                    strtoupper($invoice['currency'] ?? '') === $subscription->order->currency
                )
            ) {
                throw new HttpException(422);
            }
            SubscriptionChange::query()
                ->whereKey($change->id)
                ->where('status', 'submitting')
                ->update(['status' => 'pending', 'provider_reference' => $invoice['id']]);
            if (($invoice['status'] ?? '') === 'paid') {
                app(SubscriptionService::class)->invoice($payments->invoice($invoice['id']), $payments);

                return null;
            }
            $url = $invoice['hosted_invoice_url'] ?? null;
            $url = $this->invoiceUrl($url);
            if ($url === null) {
                throw ValidationException::withMessages(['change' => __('shop::subscriptions.invoice_unavailable')]);
            }
            SubscriptionChange::query()
                ->whereKey($change->id)
                ->where('status', 'pending')
                ->update(['checkout_url' => $url]);

            return $url;
        });
    }

    public function pending(Subscription $subscription): ?SubscriptionChange
    {
        return $subscription
            ->changes()
            ->whereIn('status', ['submitting', 'pending', 'scheduled'])
            ->orderByDesc('created_at')
            ->first();
    }

    public function payment(Subscription $subscription, User $buyer): ?string
    {
        return app(OperationLock::class)->shared(function () use ($subscription, $buyer) {
            $this->authorizeOwner($subscription, $buyer);
            if ($subscription->provider !== 'stripe') {
                abort(404);
            }
            $payments = app(StripeService::class);
            $remote = $payments->subscription($subscription->provider_reference);
            $reference = $remote['latest_invoice'] ?? null;
            $reference = is_array($reference) ? $reference['id'] ?? null : $reference;
            if (!is_string($reference)) {
                throw new HttpException(422);
            }
            $invoice = $payments->invoice($reference);
            $owner = $invoice['subscription'] ?? ($invoice['parent']['subscription_details']['subscription'] ?? null);
            $owner = is_array($owner) ? $owner['id'] ?? null : $owner;
            if (
                $owner !== $subscription->provider_reference ||
                strtoupper($invoice['currency'] ?? '') !== $subscription->order->currency
            ) {
                throw new HttpException(422);
            }
            if (($invoice['status'] ?? '') === 'paid') {
                app(SubscriptionService::class)->invoice($invoice, $payments);

                return null;
            }
            if (($invoice['status'] ?? '') !== 'open') {
                throw ValidationException::withMessages([
                    'subscription' => __('shop::subscriptions.invoice_unavailable'),
                ]);
            }

            return $this->invoiceUrl($invoice['hosted_invoice_url'] ?? null) ??
                throw ValidationException::withMessages([
                    'subscription' => __('shop::subscriptions.invoice_unavailable'),
                ]);
        });
    }

    public function synchronize(Subscription $subscription, array $remote): void
    {
        foreach ($subscription->changes()->where('status', 'pending')->get() as $change) {
            if (empty($remote['pending_update']) && !empty($remote['latest_invoice'])) {
                $invoice = app(StripeService::class)->invoice($change->provider_reference);
                if (in_array($invoice['status'] ?? '', ['void', 'uncollectible'], true)) {
                    $change->update(['status' => 'expired', 'checkout_url' => null]);
                }
            }
        }
    }

    private function resume(SubscriptionChange $change, User $buyer): ?string
    {
        $this->authorizeOwner($change->subscription, $buyer);
        $payments = app(StripeService::class);
        $invoice = $payments->invoice($change->provider_reference);
        if (($invoice['status'] ?? '') === 'paid') {
            app(SubscriptionService::class)->invoice($invoice, $payments);

            return null;
        }
        if (in_array($invoice['status'] ?? '', ['void', 'uncollectible'], true)) {
            $change->update(['status' => 'expired', 'checkout_url' => null]);

            return null;
        }
        $url = $this->invoiceUrl($invoice['hosted_invoice_url'] ?? null);
        if ($url === null) {
            throw ValidationException::withMessages(['change' => __('shop::subscriptions.invoice_unavailable')]);
        }
        $change->update(['checkout_url' => $url]);

        return $url;
    }

    private function invoiceUrl(mixed $url): ?string
    {
        if (
            $url !== null &&
            !(
                is_string($url) &&
                parse_url($url, PHP_URL_SCHEME) === 'https' &&
                parse_url($url, PHP_URL_HOST) === 'invoice.stripe.com'
            )
        ) {
            throw new HttpException(502);
        }

        return $url;
    }

    public function invoiceChange(Subscription $subscription, array $invoice, array $remote): ?SubscriptionChange
    {
        if (
            !$subscription
                ->changes()
                ->whereIn('status', ['submitting', 'pending', 'scheduled'])
                ->exists()
        ) {
            return null;
        }
        $price = app(StripeService::class)->subscriptionItem($remote)['price'];
        $price = is_array($price) ? $price['id'] : $price;
        $change = $subscription
            ->changes()
            ->whereIn('status', ['submitting', 'pending', 'scheduled', 'applied'])
            ->where('provider_price', $price)
            ->orderByDesc('created_at')
            ->first();
        if (!$change || $change->status === 'applied') {
            return null;
        }
        if ($change->status === 'scheduled') {
            if (($invoice['billing_reason'] ?? '') !== 'subscription_cycle' || now()->lt($change->effective_at)) {
                return null;
            }
        } elseif ($change->provider_reference && $change->provider_reference !== $invoice['id']) {
            return null;
        }

        return $change;
    }

    public function apply(SubscriptionChange $change, Subscription $subscription, int $orderId): void
    {
        $this->lockFunding($change);
        $allowed = $this->receives($change, $subscription);
        $orderIds = $subscription->payments()->pluck('order_id')->push($subscription->order_id);
        if ($allowed) {
            RoleGrant::query()
                ->whereIn('order_id', $orderIds)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->update(['revoked_at' => now(), 'revocation_reason' => 'plan_changed']);
        }
        $subscription->update(['product_id' => $change->product_id, 'plan_snapshot' => $change->snapshot]);
        $change->update(['status' => $allowed ? 'applied' : 'review', 'order_id' => $orderId]);
    }

    public function reconcileOrder(int $orderId): void
    {
        $subscriptions = SubscriptionPayment::query()->where('order_id', $orderId)->pluck('subscription_id');
        foreach (
            SubscriptionChange::query()
                ->whereIn('subscription_id', $subscriptions)
                ->whereIn('status', ['applied', 'review'])
                ->whereNotNull('order_id')
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get() as $change
        ) {
            $valid = $this->funded($change);
            $order = Order::query()->whereKey($change->order_id)->lockForUpdate()->firstOrFail();
            if ($change->status === 'review' && $this->receives($change, $change->subscription)) {
                $change->update(['status' => 'applied']);
                if ($order->pricing_review_reason === 'subscription_access') {
                    $order->update(['pricing_hold' => false, 'pricing_review_reason' => null]);
                }
                $replaced = $change->subscription
                    ->payments()
                    ->where('order_id', '!=', $order->id)
                    ->pluck('order_id')
                    ->push($change->subscription->order_id);
                RoleGrant::query()
                    ->whereIn('order_id', $replaced)
                    ->whereNull('revoked_at')
                    ->where('expires_at', '>', now())
                    ->update(['revoked_at' => now(), 'revocation_reason' => 'plan_changed']);
            }
            if ($change->status === 'review' && !$order->pricing_review_reason) {
                $order->update([
                    'pricing_hold' => true,
                    'pricing_review_reason' => 'subscription_access',
                    'financial_review' => true,
                ]);
            } elseif (!$valid && !$order->pricing_review_reason) {
                $order->update([
                    'pricing_hold' => true,
                    'pricing_review_reason' => 'subscription_funding',
                    'financial_review' => true,
                ]);
            } elseif ($valid && $order->pricing_review_reason === 'subscription_funding') {
                $order->update(['pricing_hold' => false, 'pricing_review_reason' => null]);
            }
        }
    }

    private function receives(SubscriptionChange $change, Subscription $subscription): bool
    {
        $buyer = $subscription->user;
        $recipient = User::query()->find($change->snapshot['recipient_id'] ?? $buyer->id);
        if (
            !$buyer ||
            $buyer->isBanned() ||
            $buyer->anonymized_at ||
            $buyer->import_locked ||
            !$recipient ||
            $recipient->isBanned() ||
            $recipient->anonymized_at ||
            $recipient->import_locked
        ) {
            return false;
        }
        $product = Product::query()->visibleTo($buyer)->visibleTo($recipient)->whereKey($change->product_id)->first();
        if (!$product) {
            return false;
        }
        try {
            $this->limits($subscription, $product);
        } catch (ValidationException) {
            return false;
        }

        return true;
    }

    private function limits(Subscription $subscription, Product $product): void
    {
        $recipient = User::query()->findOrFail(
            $this->snapshot($subscription)['recipient_id'] ?? $subscription->user_id,
        );
        $replaced = $subscription->payments()->pluck('order_id')->push($subscription->order_id)->unique()->all();
        app(PurchaseLimits::class)->assert($recipient, $product, 1, collect([$product]), replacedOrders: $replaced);
    }

    private function lockFunding(SubscriptionChange $change): void
    {
        $orders = OrderItem::query()->whereIn('id', array_keys($change->funding_snapshot ?? []))->pluck('order_id');
        Order::query()->whereIn('id', $orders)->orderBy('id')->lockForUpdate()->get();
    }

    private function funded(SubscriptionChange $change): bool
    {
        $funding = $change->funding_snapshot ?? [];
        $net = app(UpgradeLedger::class)->netAmounts(array_keys($funding), lock: true);

        return collect($funding)->every(fn ($required, $item) => ($net[$item] ?? 0) >= $required);
    }

    public function snapshot(Subscription $subscription): array
    {
        return $subscription->plan_snapshot ?:
            array_replace(
                OrderItem::query()->where('order_id', $subscription->order_id)->firstOrFail()->getAttributes(),
                ['unit_minor' => $subscription->order->total_minor],
            );
    }

    public function invoiceSnapshot(Subscription $subscription, array $invoice): array
    {
        $current = $this->snapshot($subscription);
        $prices = collect($invoice['lines']['data'] ?? [])
            ->map(function (array $line) {
                $price = $line['pricing']['price_details']['price'] ?? ($line['price'] ?? null);

                return is_array($price) ? $price['id'] ?? null : $price;
            })
            ->filter()
            ->unique()
            ->values();
        if ($prices->isEmpty() || $prices->count() !== 1 || ($current['provider_price'] ?? null) === $prices[0]) {
            return $current;
        }
        $changes = $subscription->changes()->orderByDesc('created_at')->get();
        foreach ($changes as $change) {
            $previous = $change->snapshot['previous_plan'] ?? [];
            if (
                ($previous['provider_price'] ?? null) === $prices[0] &&
                in_array($change->status, ['applied', 'review'], true)
            ) {
                $previous['access_ends_at'] = $change->effective_at->timestamp;

                return $previous;
            }
        }
        foreach ($changes as $change) {
            if ($change->provider_price === $prices[0] && in_array($change->status, ['applied', 'review'], true)) {
                return $change->snapshot;
            }
            $previous = $change->snapshot['previous_plan'] ?? [];
            if (!isset($current['provider_price']) && ($previous['provider_price'] ?? null) === $prices[0]) {
                return $previous;
            }
        }
        throw new HttpException(422);
    }

    private function authorize(Subscription $subscription, User $buyer): void
    {
        $this->authorizeOwner($subscription, $buyer);
        if (!$this->available($subscription) || !app(GatewayRegistry::class)->enabled($subscription->provider)) {
            throw ValidationException::withMessages(['subscription' => __('shop::subscriptions.unavailable')]);
        }
    }

    private function authorizeOwner(Subscription $subscription, User $buyer): void
    {
        if ($subscription->user_id !== $buyer->id) {
            abort(404);
        }
        $recipient = User::query()->find($this->snapshot($subscription)['recipient_id'] ?? $buyer->id);
        if (
            $buyer->isBanned() ||
            $buyer->anonymized_at ||
            $buyer->import_locked ||
            !$recipient ||
            $recipient->isBanned() ||
            $recipient->anonymized_at ||
            $recipient->import_locked ||
            !app(PermissionAccess::class)->allows($buyer, 'shop.view')
        ) {
            throw new HttpException(403);
        }
    }
}
