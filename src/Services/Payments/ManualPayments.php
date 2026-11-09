<?php

namespace KraftInk\Plugins\Shop\Services\Payments;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\Audit;
use App\Kraft\System\OperationLock;
use App\Models\Accounts\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use KraftInk\Plugins\Shop\Models\Billing\ManualPayment;
use KraftInk\Plugins\Shop\Models\Billing\ManualRefund;
use KraftInk\Plugins\Shop\Models\Billing\Refund;
use KraftInk\Plugins\Shop\Models\Orders\Order;
use KraftInk\Plugins\Shop\Models\Orders\OrderItem;
use KraftInk\Plugins\Shop\Services\Finance\RefundService;
use KraftInk\Plugins\Shop\Services\Orders\OrderService;
use KraftInk\Plugins\Shop\Services\Roles\RolePolicy;

class ManualPayments
{
    public function payment(
        int $orderId,
        int $amount,
        string $currency,
        string $key,
        string $reference,
        string $note,
        User $actor,
    ): void {
        app(OperationLock::class)->shared(function () use (
            $orderId,
            $amount,
            $currency,
            $key,
            $reference,
            $note,
            $actor,
        ) {
            $this->validate($key, $reference, $note, $actor);
            if (!$actor->hasPermission('shop.payments.record')) {
                throw new HttpException(403);
            }
            DB::transaction(function () use ($orderId, $amount, $currency, $key, $reference, $note, $actor) {
                $order = Order::query()->where('id', $orderId)->lockForUpdate()->first();
                if (!($order && $order->provider === 'manual' && !$order->recurring && $order->total_minor === $amount && $order->currency === $currency)) {
                    throw new HttpException(422, __('shop::commerce.payments.manual.mismatch'));
                }
                $existing = ManualPayment::query()->where('order_id', $orderId)->first();
                if ($existing) {
                    if (!($existing->id === $key && $existing->reference === $reference && $existing->note === $note && $existing->amount_minor === $amount && $existing->currency === $currency)) {
                        throw new HttpException(409);
                    }

                    return;
                }
                if (!(!$order->paid_at && in_array($order->status, ['pending', 'cancelled'], true))) {
                    throw new HttpException(422, __('shop::commerce.payments.manual.unavailable'));
                }
                /** @var OrderItem $item */
                foreach (OrderItem::query()->where('order_id', $orderId)->get() as $item) {
                    app(RolePolicy::class)->assertManage($actor, $item->role_id);
                    if (($item->wallet_credit_minor > 0 || $item->kind === 'giftcard') && !$actor->hasPermission('wallet.manage')) {
                        throw new HttpException(403);
                    }
                }
                if (ManualPayment::query()->where('reference_hash', $this->hash($reference))->exists()) {
                    throw new HttpException(409, __('shop::commerce.payments.manual.reference_used'));
                }
                ManualPayment::query()->insert([
                    'id' => $key,
                    'order_id' => $orderId,
                    'reference' => $reference,
                    'reference_hash' => $this->hash($reference),
                    'amount_minor' => $amount,
                    'currency' => $currency,
                    'actor_id' => $actor->id,
                    'note' => $note,
                    'confirmed_at' => now(),
                ]);
                Audit::record(
                    'shop.manual.payment_recorded',
                    ['order_id' => $orderId, 'record_id' => $key, 'amount_minor' => $amount, 'currency' => $currency],
                    $actor,
                );
            }, 3);
            $order = Order::query()->find($orderId);
            if ($order->auto_expire && now()->gte($order->expires_at)) {
                app(OrderService::class)->cancel($orderId);
            }
            app(OrderService::class)->complete(
                $orderId,
                'manual',
                'manual:'.$key,
                $amount,
                $currency,
                null,
                'manual:'.$key,
                'manual',
            );
        });
    }

    public function refund(
        string $refundId,
        int $amount,
        string $currency,
        string $key,
        string $reference,
        string $note,
        User $actor,
    ): void {
        app(OperationLock::class)->shared(function () use (
            $refundId,
            $amount,
            $currency,
            $key,
            $reference,
            $note,
            $actor,
        ) {
            $this->validate($key, $reference, $note, $actor);
            if (!($actor->hasPermission('shop.payments.record') && $actor->hasPermission('shop.refunds.manage'))) {
                throw new HttpException(403);
            }
            DB::transaction(function () use ($refundId, $amount, $currency, $key, $reference, $note, $actor) {
                $orderId = Refund::query()->where('id', $refundId)->value('order_id');
                if (!($orderId && Order::query()->where('id', $orderId)->lockForUpdate()->first())) {
                    abort(404);
                }
                $refund = Refund::query()->where('id', $refundId)->lockForUpdate()->first();
                if (!($refund && $refund->provider === 'manual' && $refund->external_minor === $amount && $refund->currency === $currency)) {
                    throw new HttpException(422, __('shop::commerce.payments.manual.mismatch'));
                }
                $existing = ManualRefund::query()->where('refund_id', $refundId)->first();
                if ($existing) {
                    if (!($existing->id === $key && $existing->reference === $reference && $existing->note === $note && $existing->amount_minor === $amount && $existing->currency === $currency)) {
                        throw new HttpException(409);
                    }

                    return;
                }
                if (!in_array($refund->status, ['pending', 'processing'], true)) {
                    throw new HttpException(422, __('shop::commerce.payments.manual.unavailable'));
                }
                if (ManualRefund::query()->where('reference_hash', $this->hash($reference))->exists()) {
                    throw new HttpException(409, __('shop::commerce.payments.manual.reference_used'));
                }
                ManualRefund::query()->insert([
                    'id' => $key,
                    'order_id' => $refund->order_id,
                    'refund_id' => $refundId,
                    'reference' => $reference,
                    'reference_hash' => $this->hash($reference),
                    'amount_minor' => $amount,
                    'currency' => $currency,
                    'actor_id' => $actor->id,
                    'note' => $note,
                    'confirmed_at' => now(),
                ]);
                Audit::record(
                    'shop.manual.refund_recorded',
                    [
                        'order_id' => $refund->order_id,
                        'refund_id' => $refundId,
                        'record_id' => $key,
                        'amount_minor' => $amount,
                        'currency' => $currency,
                    ],
                    $actor,
                );
                app(RefundService::class)->reconcile($refundId, 'manual:refund:'.$refundId);
            }, 3);
        });
    }

    private function hash(string $reference): string
    {
        if (!(trim($reference) === $reference && $reference !== '' && strlen($reference) <= 120 && !preg_match('/[\x00-\x1f\x7f]/', $reference))) {
            throw new HttpException(422, __('shop::commerce.payments.manual.reference_invalid'));
        }

        return hash('sha256', mb_strtolower($reference));
    }

    private function validate(string $key, string $reference, string $note, User $actor): void
    {
        if ($actor->isBanned() || $actor->anonymized_at || $actor->import_locked) {
            throw new HttpException(403);
        }
        if (!(Str::isUuid($key) && trim($note) !== '' && mb_strlen($note) <= 1000)) {
            throw new HttpException(422);
        }
        $this->hash($reference);
    }
}
