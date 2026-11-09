<?php

namespace KraftInk\Plugins\Shop\Services;

use App\Kraft\Notifications\Notifications;
use App\Models\Accounts\User;
use KraftInk\Plugins\Shop\Models\Orders\Order;

class PurchaseNotifications
{
    public function paid(int $id): void
    {
        $order = Order::query()->find($id);
        $this->notice(
            $order,
            $order->pricing_hold || $order->giftcard_hold || $order->financial_hold ? 'review' : 'paid',
        );
    }

    public function refunded(object $refund): void
    {
        $order = Order::query()->find($refund->order_id);
        $this->notice($order, 'refunded', 'refund:'.$refund->id);
    }

    private function notice(object $order, string $kind, ?string $key = null): void
    {
        $user = User::query()->find($order->user_id);
        if (!$user || $user->anonymized_at) {
            return;
        }
        app(Notifications::class)->notify(
            'shop.order.'.$kind,
            $user,
            ['order_id' => $order->id],
            $key ?? 'order:'.$order->id,
            resource: 'order:'.$order->id,
        );
    }
}
