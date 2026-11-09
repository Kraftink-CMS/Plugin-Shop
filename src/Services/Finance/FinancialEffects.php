<?php

namespace KraftInk\Plugins\Shop\Services\Finance;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use KraftInk\Plugins\Shop\Models\Delivery\Delivery;
use KraftInk\Plugins\Shop\Models\Orders\Order;
use KraftInk\Plugins\Shop\Models\Orders\OrderItem;
use App\Kraft\Link\CommandIdentity;
use KraftInk\Plugins\Shop\Services\Pricing;
use KraftInk\Plugins\Shop\Services\Orders\SubscriptionPlans;

class FinancialEffects
{
    public function reverse(int $orderId): void
    {
        $order = Order::query()->where('id', $orderId)->lockForUpdate()->first();
        if (!($order && $order->hasValidBuyer())) {
            throw new HttpException(422);
        }
        if ($order->reversed_at) {
            return;
        }
        $order
            ->roleGrants()
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revocation_reason' => 'financial']);
        $review = Delivery::query()
            ->where('order_id', $orderId)
            ->where('kind', 'grant')
            ->whereIn('status', ['processing', 'failed'])
            ->exists();
        Delivery::query()
            ->where('order_id', $orderId)
            ->whereIn('status', ['pending', 'processing'])
            ->update(['status' => 'cancelled']);
        /** @var OrderItem $item */
        foreach (OrderItem::query()->where('order_id', $orderId)->get() as $item) {
            $review = app(CreditRecovery::class)->record($order, $item) || $review;
            /** @var Collection<int, Delivery> $deliveries */
            $deliveries = Delivery::query()->where('order_item_id', $item->id)->where('kind', 'grant')->get();
            if ($deliveries->isEmpty() || $deliveries->contains(fn ($delivery) => $delivery->status !== 'delivered')) {
                $review = $review || $deliveries->contains('status', 'delivered');

                continue;
            }
            for ($quantity = 0; $quantity < $item->quantity; $quantity++) {
                foreach (json_decode($item->refund_commands ?: '[]', true) as $command) {
                    Delivery::query()->insert([
                        'id' => (string) Str::uuid(),
                        'order_id' => $orderId,
                        'order_item_id' => $item->id,
                        'kind' => 'reversal',
                        'user_id' => $order->user_id,
                        'guest_id' => $order->guest_id,
                        'command' => app(CommandIdentity::class)->expand($item, $command),
                        'status' => 'pending',
                        'attempts' => 0,
                        'server_id' => $item->server_id,
                        'game' => $item->game,
                        'player_uuid' => $item->player_uuid,
                        'player_name' => $item->player_name,
                        'requires_online' => $item->requires_online,
                        'available_at' => now()->addMinutes(6),
                        'created_at' => now(),
                    ]);
                }
            }
        }
        Order::query()
            ->where('id', $orderId)
            ->update(['reversed_at' => now(), 'financial_review' => $review]);
        app(Pricing\UpgradeLedger::class)->reconcileOrder($orderId);
        app(SubscriptionPlans::class)->reconcileOrder($orderId);
    }
}
