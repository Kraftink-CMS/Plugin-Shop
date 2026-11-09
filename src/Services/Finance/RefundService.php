<?php

namespace KraftInk\Plugins\Shop\Services\Finance;

use Symfony\Component\HttpKernel\Exception\HttpException;
use KraftInk\Plugins\Shop\Contracts\Payments\PaymentGateway;
use App\Kraft\Audit;
use App\Kraft\System\OperationLock;
use App\Models\Accounts\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use KraftInk\Plugins\Shop\Contracts\Payments\RefundsPayments;
use KraftInk\Plugins\Shop\Contracts\Payments\ReviewsDisputes;
use KraftInk\Plugins\Shop\Data\Payments\DisputeResult;
use KraftInk\Plugins\Shop\Data\Payments\RefundResult;
use KraftInk\Plugins\Shop\Models\Billing\Dispute;
use KraftInk\Plugins\Shop\Models\Billing\FinancialEvent;
use KraftInk\Plugins\Shop\Models\Billing\Refund;
use KraftInk\Plugins\Shop\Models\GiftCards\GiftCard;
use KraftInk\Plugins\Shop\Models\Orders\Order;
use KraftInk\Plugins\Shop\Services\Commerce;
use KraftInk\Plugins\Shop\Services\GiftCards;
use KraftInk\Plugins\Shop\Services\Payments\GatewayRegistry;
use KraftInk\Plugins\Shop\Services\Pricing;
use KraftInk\Plugins\Shop\Services\PurchaseNotifications;
use KraftInk\Plugins\Shop\Services\Orders\SubscriptionPlans;
use Throwable;
use App\Kraft\Events\PluginEvents;

class RefundService
{
    public function request(int $orderId, int $amount, string $key, string $reason, User $actor): object
    {
        $operationLease = app(OperationLock::class)->acquireShared();
        try {
            $refund = DB::transaction(function () use ($orderId, $amount, $key, $reason, $actor) {
                $order = Order::query()->where('id', $orderId)->lockForUpdate()->first();
                if (!($order && $order->hasValidBuyer())) {
                    abort(404);
                }
                $existing = Refund::query()->where('request_key', $key)->first();
                if ($existing) {
                    if (
                        !(
                            $existing->order_id === $orderId &&
                            $existing->amount_minor === $amount &&
                            $existing->reason === $reason
                        )
                    ) {
                        throw new HttpException(409);
                    }

                    return $existing;
                }
                if (
                    !(
                        $order->status === 'paid' &&
                        !$order->financial_hold &&
                        $order->provider !== 'legacy' &&
                        $order->pricing_review_reason !== 'imported'
                    )
                ) {
                    throw new HttpException(422, __('shop::commerce.refunds.order_unavailable'));
                }
                $allocated = Refund::query()
                    ->where('order_id', $orderId)
                    ->where('status', '!=', 'failed')
                    ->selectRaw(
                        'COALESCE(SUM(amount_minor), 0) AS total, COALESCE(SUM(external_minor), 0) AS external, COALESCE(SUM(wallet_minor), 0) AS wallet',
                    )
                    ->toBase()
                    ->first();
                if (
                    !(
                        $amount > 0 &&
                        $amount <=
                            $order->total_minor +
                                $order->giftcard_minor +
                                ($order->wallet_minor ?? 0) -
                                $allocated->total
                    )
                ) {
                    throw new HttpException(422, __('shop::commerce.refunds.amount_exceeded'));
                }
                $external = min($amount, $order->total_minor - $allocated->external);
                $wallet = min($amount - $external, ($order->wallet_minor ?? 0) - $allocated->wallet);
                if ($external > 0) {
                    if (!(app(GatewayRegistry::class)->get($order->provider) instanceof RefundsPayments)) {
                        throw new HttpException(422, __('shop::commerce.refunds.unsupported'));
                    }
                }
                Refund::query()->insert([
                    'id' => $key,
                    'request_key' => $key,
                    'order_id' => $orderId,
                    'actor_id' => $actor->id,
                    'provider' => $order->provider,
                    'amount_minor' => $amount,
                    'external_minor' => $external,
                    'wallet_minor' => $wallet,
                    'giftcard_minor' => $amount - $external - $wallet,
                    'currency' => $order->currency,
                    'status' => 'pending',
                    'reason' => $reason,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                Audit::record(
                    'shop.refund.requested',
                    ['id' => $key, 'order_id' => $orderId, 'amount_minor' => $amount],
                    $actor,
                );

                return Refund::query()->find($key);
            }, 3);

            return $this->process($refund->id);
        } finally {
            $operationLease->release();
        }
    }

    public function process(string $id): object
    {
        $operationLease = app(OperationLock::class)->acquireShared();
        try {
            $refund = Refund::query()->find($id);
            if ($refund === null) {
                abort(404);
            }
            if (in_array($refund->status, ['succeeded', 'failed'], true)) {
                if ($refund->status === 'succeeded') {
                    app(WalletReturns::class)->retryForRefund($refund->id);
                }

                return $refund;
            }
            $order = Order::query()->find($refund->order_id);
            if (!($order && !$order->financial_hold)) {
                throw new HttpException(422, __('shop::commerce.refunds.order_unavailable'));
            }
            if ($refund->external_minor === 0) {
                $this->apply(
                    $refund,
                    new RefundResult('local:'.$id, '', 0, $refund->currency, 'succeeded', $id),
                    'local:'.$id,
                );

                return Refund::query()->find($id);
            }
            $gateway = app(GatewayRegistry::class)->get($refund->provider);
            if (!($gateway instanceof RefundsPayments)) {
                throw new HttpException(422, __('shop::commerce.refunds.unsupported'));
            }
            /** @var PaymentGateway&RefundsPayments $gateway */
            if ($refund->provider_reference) {
                $this->apply(
                    $refund,
                    $gateway->getRefund($refund->provider_reference),
                    'poll:'.$id.':'.Str::uuid(),
                );

                return Refund::query()->find($id);
            }
            if (
                !in_array('idempotent_local_refunds', $gateway->capabilities(), true) &&
                $refund->submitted_at &&
                now()->diffInMinutes($refund->submitted_at, true) > 50
            ) {
                throw new HttpException(422, __('shop::commerce.refunds.reconcile_required'));
            }
            $payment = $gateway->payment($order);
            if (!($payment->amount === $order->total_minor && $payment->currency === $order->currency)) {
                throw new HttpException(422, __('shop::commerce.refunds.payment_mismatch'));
            }
            Order::query()
                ->where('id', $order->id)
                ->update(['payment_reference' => $payment->id, 'payment_kind' => $payment->kind]);
            Refund::query()
                ->where('id', $id)
                ->update([
                    'payment_reference' => $payment->id,
                    'status' => 'processing',
                    'error' => null,
                    'submitted_at' => $refund->submitted_at ?: now(),
                    'updated_at' => now(),
                ]);
            $refund = Refund::query()->find($id);
            try {
                $result = $gateway->refund($payment, $refund);
                $this->apply($refund, $result, 'response:'.$id.':'.$result->status);
            } catch (Throwable $exception) {
                Refund::query()
                    ->where('id', $id)
                    ->where('status', 'processing')
                    ->update(['error' => 'verification_required']);
                report($exception);
            }

            return Refund::query()->find($id);
        } finally {
            $operationLease->release();
        }
    }

    public function reconcile(string $id, string $reference): object
    {
        $operationLease = app(OperationLock::class)->acquireShared();
        try {
            $refund = Refund::query()->find($id);
            if ($refund === null) {
                abort(404);
            }
            $gateway = app(GatewayRegistry::class)->get($refund->provider);
            if (!($gateway instanceof RefundsPayments)) {
                throw new HttpException(422);
            }
            /** @var RefundsPayments $gateway */
            $this->apply($refund, $gateway->getRefund($reference), 'reconcile:'.$id.':'.Str::uuid());
            Audit::record('shop.refund.reconciled', ['id' => $id]);

            return Refund::query()->find($id);
        } finally {
            $operationLease->release();
        }
    }

    public function webhookRefund(string $provider, RefundResult $result, string $eventId): void
    {
        $operationLease = app(OperationLock::class)->acquireShared();
        try {
            $refund = Refund::query()
                ->where('provider', $provider)
                ->where(function ($query) use ($result) {
                    $query->where('provider_reference', $result->id);
                    if ($result->requestKey && Str::isUuid($result->requestKey)) {
                        $query->orWhere('id', $result->requestKey);
                    }
                })
                ->first();
            if (!$refund) {
                $refund = DB::transaction(function () use ($provider, $result) {
                    $order = Order::query()
                        ->where('provider', $provider)
                        ->where('payment_reference', $result->paymentReference)
                        ->lockForUpdate()
                        ->first();
                    if (!($order && in_array($order->status, ['paid', 'refunded'], true))) {
                        throw new HttpException(422, __('shop::commerce.refunds.payment_unmatched'));
                    }
                    if (
                        !(
                            $result->currency === $order->currency &&
                            $result->amount > 0 &&
                            $result->amount <= $order->total_minor
                        )
                    ) {
                        throw new HttpException(422, __('shop::commerce.refunds.payment_mismatch'));
                    }
                    $existing = Refund::query()
                        ->where('provider', $provider)
                        ->where('provider_reference', $result->id)
                        ->first();
                    if ($existing) {
                        return $existing;
                    }
                    $reserved = Refund::query()
                        ->where('order_id', $order->id)
                        ->where('status', '!=', 'failed')
                        ->sum('external_minor');
                    if (!($reserved + $result->amount <= $order->total_minor)) {
                        throw new HttpException(409, __('shop::commerce.refunds.payment_mismatch'));
                    }
                    $giftcard =
                        $reserved + $result->amount === $order->total_minor
                            ? $order->giftcard_minor -
                                Refund::query()
                                    ->where('order_id', $order->id)
                                    ->where('status', '!=', 'failed')
                                    ->sum('giftcard_minor')
                            : 0;
                    $wallet =
                        $reserved + $result->amount === $order->total_minor
                            ? ($order->wallet_minor ?? 0) -
                                Refund::query()
                                    ->where('order_id', $order->id)
                                    ->where('status', '!=', 'failed')
                                    ->sum('wallet_minor')
                            : 0;
                    $id = (string) Str::uuid();
                    Refund::query()->insert([
                        'id' => $id,
                        'request_key' => $id,
                        'order_id' => $order->id,
                        'provider' => $provider,
                        'payment_reference' => $result->paymentReference,
                        'provider_reference' => $result->id,
                        'amount_minor' => $result->amount + $giftcard + $wallet,
                        'external_minor' => $result->amount,
                        'giftcard_minor' => $giftcard,
                        'wallet_minor' => $wallet,
                        'currency' => $result->currency,
                        'status' => 'pending',
                        'reason' => 'provider',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    return Refund::query()->find($id);
                }, 3);
            }
            $this->apply($refund, $result, $provider.':refund:'.$eventId);
        } finally {
            $operationLease->release();
        }
    }

    public function dispute(string $provider, string $reference, string $eventId): void
    {
        $operationLease = app(OperationLock::class)->acquireShared();
        try {
            $gateway = app(GatewayRegistry::class)->get($provider);
            if (!($gateway instanceof ReviewsDisputes)) {
                throw new HttpException(422);
            }
            /** @var ReviewsDisputes $gateway */
            $result = $gateway->getDispute($reference);
            $this->recordDispute($provider, $result, $eventId);
        } finally {
            $operationLease->release();
        }
    }

    public function reviewDispute(int $id, string $outcome, string $note, User $actor): void
    {
        $operationLease = app(OperationLock::class)->acquireShared();
        try {
            if (!($actor->hasPermission('shop.refunds.manage') && in_array($outcome, ['won', 'lost'], true))) {
                throw new HttpException(403);
            }
            $dispute = Dispute::query()->find($id);
            if (!($dispute && $dispute->status === 'review')) {
                throw new HttpException(422);
            }
            $gateway = app(GatewayRegistry::class)->get($dispute->provider);
            if (!($gateway instanceof ReviewsDisputes)) {
                throw new HttpException(422);
            }
            /** @var ReviewsDisputes $gateway */
            $remote = $gateway->getDispute($dispute->provider_reference);
            if (
                !(
                    $remote->status === 'review' &&
                    $remote->paymentReference === $dispute->payment_reference &&
                    $remote->amount === $dispute->amount_minor &&
                    $remote->currency === $dispute->currency
                )
            ) {
                throw new HttpException(422);
            }
            DB::transaction(function () use ($dispute, $remote, $outcome, $note, $actor) {
                $this->event(
                    $dispute->order_id,
                    'manual-dispute:'.Str::uuid(),
                    'manual_dispute',
                    $remote->id,
                    $outcome,
                    $remote->amount,
                    $remote->currency,
                );
                $this->recordDispute(
                    $dispute->provider,
                    new DisputeResult(
                        $remote->id,
                        $remote->paymentReference,
                        $remote->amount,
                        $remote->currency,
                        $outcome,
                    ),
                    'manual:'.Str::uuid(),
                );
                Audit::record(
                    'shop.dispute.reviewed',
                    ['id' => $dispute->id, 'outcome' => $outcome, 'note' => $note],
                    $actor,
                );
            }, 3);
        } finally {
            $operationLease->release();
        }
    }

    public function recordDispute(string $provider, DisputeResult $result, string $eventId): void
    {
        $operationLease = app(OperationLock::class)->acquireShared();
        try {
            if (!(in_array($result->status, ['open', 'won', 'lost', 'review'], true) && $result->amount > 0)) {
                throw new HttpException(422);
            }
            DB::transaction(function () use ($provider, $result, $eventId) {
                $order = Order::query()
                    ->where('provider', $provider)
                    ->where('payment_reference', $result->paymentReference)
                    ->lockForUpdate()
                    ->first();
                if (
                    !(
                        $order &&
                        $order->paid_at &&
                        $result->currency === $order->currency &&
                        $result->amount <= $order->total_minor
                    )
                ) {
                    throw new HttpException(422, __('shop::commerce.refunds.payment_unmatched'));
                }
                if ($result->status === 'review') {
                    $decision = FinancialEvent::query()
                        ->where('order_id', $order->id)
                        ->where('type', 'manual_dispute')
                        ->where('reference', $result->id)
                        ->orderByDesc('id')
                        ->value('status');
                    if (in_array($decision, ['won', 'lost'], true)) {
                        $result = new DisputeResult(
                            $result->id,
                            $result->paymentReference,
                            $result->amount,
                            $result->currency,
                            $decision,
                        );
                    }
                }
                $key = $provider.':dispute:'.$eventId;
                if (FinancialEvent::query()->where('event_key', $key)->exists()) {
                    return;
                }
                Dispute::query()->updateOrInsert(
                    ['provider' => $provider, 'provider_reference' => $result->id],
                    [
                        'order_id' => $order->id,
                        'payment_reference' => $result->paymentReference,
                        'amount_minor' => $result->amount,
                        'currency' => $result->currency,
                        'status' => $result->status,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
                $lost = Dispute::query()->where('order_id', $order->id)->where('status', 'lost')->exists();
                $hold =
                    $lost ||
                    Dispute::query()
                        ->where('order_id', $order->id)
                        ->whereIn('status', ['open', 'review'])
                        ->exists();
                $status = $lost
                    ? 'charged_back'
                    : ($order->refunded_minor ===
                    $order->total_minor + $order->giftcard_minor + ($order->wallet_minor ?? 0)
                        ? 'refunded'
                        : 'paid');
                Order::query()
                    ->where('id', $order->id)
                    ->update([
                        'financial_hold' => $hold,
                        'financial_review' => $order->financial_review ||
                            $result->status === 'review' ||
                            ($order->reversed_at && $result->status === 'won'),
                        'status' => $status,
                        'updated_at' => now(),
                    ]);
                if ($lost) {
                    app(FinancialEffects::class)->reverse($order->id);
                }
                app(GiftCards\CardService::class)->reconcileOrder($order->id);
                app(Pricing\UpgradeLedger::class)->reconcileOrder($order->id);
                app(SubscriptionPlans::class)->reconcileOrder($order->id);
                $this->event(
                    $order->id,
                    $key,
                    'dispute',
                    $result->id,
                    $result->status,
                    $result->amount,
                    $result->currency,
                );
                Audit::record('shop.dispute.'.$result->status, [
                    'order_id' => $order->id,
                    'reference' => $result->id,
                ]);
            }, 3);
        } finally {
            $operationLease->release();
        }
    }

    private function apply(object $refund, RefundResult $result, string $eventKey): void
    {
        if (
            !(
                $result->amount === $refund->external_minor &&
                $result->currency === $refund->currency &&
                in_array($result->status, ['pending', 'succeeded', 'failed'], true)
            )
        ) {
            throw new HttpException(422, __('shop::commerce.refunds.payment_mismatch'));
        }
        if ($refund->external_minor > 0) {
            if (!($refund->payment_reference && hash_equals($refund->payment_reference, $result->paymentReference))) {
                throw new HttpException(422, __('shop::commerce.refunds.payment_mismatch'));
            }
        }
        if (!(!$result->requestKey || $result->requestKey === $refund->id)) {
            throw new HttpException(409, __('shop::commerce.refunds.payment_mismatch'));
        }
        DB::transaction(function () use ($refund, $result, $eventKey) {
            $order = Order::query()->where('id', $refund->order_id)->lockForUpdate()->first();
            $refund = Refund::query()->where('id', $refund->id)->lockForUpdate()->first();
            if ($refund->status === 'succeeded' || FinancialEvent::query()->where('event_key', $eventKey)->exists()) {
                return;
            }
            if (!(!$refund->provider_reference || $refund->provider_reference === $result->id)) {
                throw new HttpException(409);
            }
            $otherRefunds = Refund::query()
                ->where('order_id', $order->id)
                ->where('id', '!=', $refund->id)
                ->where('status', '!=', 'failed')
                ->sum('amount_minor');
            if (
                !(
                    $result->status === 'failed' ||
                    $otherRefunds + $refund->amount_minor <=
                        $order->total_minor + $order->giftcard_minor + ($order->wallet_minor ?? 0)
                )
            ) {
                throw new HttpException(409, __('shop::commerce.refunds.payment_mismatch'));
            }
            Refund::query()
                ->where('id', $refund->id)
                ->update([
                    'provider_reference' => $result->id,
                    'status' => $result->status,
                    'error' => null,
                    'processed_at' => $result->status === 'succeeded' ? now() : null,
                    'updated_at' => now(),
                ]);
            $fullyRefunded = false;
            if ($result->status === 'succeeded') {
                app(WalletReturns::class)->queue(
                    $order,
                    (int) ($refund->wallet_minor ?? 0),
                    'refund:'.$refund->id,
                    $refund->id,
                );
                if ($refund->giftcard_minor > 0 && $order->giftcard_id) {
                    GiftCard::query()
                        ->where('id', $order->giftcard_id)
                        ->increment('balance_minor', $refund->giftcard_minor);
                }
                $refunded = (int) Refund::query()
                    ->where('order_id', $order->id)
                    ->where('status', 'succeeded')
                    ->sum('amount_minor');
                $fullyRefunded =
                    $refunded === $order->total_minor + $order->giftcard_minor + ($order->wallet_minor ?? 0);
                Order::query()
                    ->where('id', $order->id)
                    ->update([
                        'refunded_minor' => $refunded,
                        'status' => $fullyRefunded ? 'refunded' : $order->status,
                        'updated_at' => now(),
                    ]);
                app(GiftCards\CardService::class)->reconcileOrder($order->id);
                app(Pricing\UpgradeLedger::class)->reconcileOrder($order->id);
                app(SubscriptionPlans::class)->reconcileOrder($order->id);
                app(Commerce\ReceiptService::class)->refund($refund->id);
                app(PurchaseNotifications::class)->refunded($refund);
                if ($fullyRefunded) {
                    app(FinancialEffects::class)->reverse($order->id);
                }
            }
            $this->event(
                $order->id,
                $eventKey,
                'refund',
                $result->id,
                $result->status,
                $refund->amount_minor,
                $refund->currency,
            );
            Audit::record('shop.refund.'.$result->status, [
                'id' => $refund->id,
                'order_id' => $order->id,
                'amount_minor' => $refund->amount_minor,
            ]);
            if ($result->status === 'succeeded') {
                app(PluginEvents::class)->publish(
                    'shop.refund.succeeded',
                    [
                        'refund_id' => $refund->id,
                        'order_id' => $order->id,
                        'user_id' => $order->user_id,
                        'guest_id' => $order->guest_id,
                        'amount_minor' => (int) $refund->amount_minor,
                        'currency' => $refund->currency,
                        'full' => $fullyRefunded,
                    ],
                    'shop.refund.succeeded:'.$refund->id,
                );
            }
        }, 3);
    }

    private function event(
        int $orderId,
        string $key,
        string $type,
        string $reference,
        string $status,
        int $amount,
        string $currency,
    ): void {
        FinancialEvent::query()->insert([
            'order_id' => $orderId,
            'event_key' => $key,
            'type' => $type,
            'reference' => $reference,
            'status' => $status,
            'amount_minor' => $amount,
            'currency' => $currency,
            'created_at' => now(),
        ]);
    }
}
