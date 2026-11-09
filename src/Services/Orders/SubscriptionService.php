<?php

namespace KraftInk\Plugins\Shop\Services\Orders;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\System\OperationLock;
use App\Models\Accounts\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Kraft\Games\GameRegistry;
use KraftInk\Plugins\Shop\Data\Payments\PaymentReference;
use KraftInk\Plugins\Shop\Models\Catalog\Product;
use KraftInk\Plugins\Shop\Models\Orders\Order;
use KraftInk\Plugins\Shop\Models\Orders\OrderItem;
use KraftInk\Plugins\Shop\Models\Orders\Subscription;
use KraftInk\Plugins\Shop\Models\Orders\SubscriptionPayment;
use KraftInk\Plugins\Shop\Models\Orders\SubscriptionChange;
use KraftInk\Plugins\Shop\Services\Commerce;
use KraftInk\Plugins\Shop\Services\Payments\PayPalService;
use KraftInk\Plugins\Shop\Services\Payments\StripeService;

class SubscriptionService
{
    public function canAddPaymentMethod(Subscription $subscription): bool
    {
        return $subscription->provider === 'stripe' &&
            $subscription->status === 'trialing' &&
            !$subscription->cancel_at_period_end &&
            $subscription->trial?->ends_at?->isFuture() &&
            empty($subscription->plan_snapshot['payment_method']['saved']);
    }

    public function paymentMethod(Subscription $subscription, User $buyer): string
    {
        if ($subscription->user_id !== $buyer->id) {
            abort(404);
        }
        if (!$this->canAddPaymentMethod($subscription)) {
            throw ValidationException::withMessages([
                'subscription' => __('shop::subscriptions.payment_method_unavailable'),
            ]);
        }

        return app(OperationLock::class)->shared(function () use ($subscription, $buyer) {
            $payments = app(StripeService::class);
            $remote = $payments->subscription($subscription->provider_reference);
            $customer = $remote['customer'] ?? null;
            $knownCustomer = $subscription->plan_snapshot['payment_method']['customer_id'] ?? null;
            if (
                !(
                    is_string($customer) &&
                    str_starts_with($customer, 'cus_') &&
                    ($knownCustomer === null || $knownCustomer === $customer) &&
                    ($remote['metadata']['order_reference'] ?? null) === $subscription->order->reference &&
                    ($remote['status'] ?? null) === 'trialing' &&
                    ($remote['trial_end'] ?? 0) > now()->timestamp &&
                    empty($remote['cancel_at_period_end'])
                )
            ) {
                throw ValidationException::withMessages([
                    'subscription' => __('shop::subscriptions.payment_method_unavailable'),
                ]);
            }
            if ($payments->hasPaymentMethod($remote)) {
                $this->synchronize($remote);

                return route('plugins.shop.public.orders.index');
            }
            $context = DB::transaction(function () use ($subscription, $buyer, $customer) {
                $current = Subscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
                if ($current->user_id !== $buyer->id) {
                    abort(404);
                }
                if (!$this->canAddPaymentMethod($current)) {
                    throw ValidationException::withMessages([
                        'subscription' => __('shop::subscriptions.payment_method_unavailable'),
                    ]);
                }
                $snapshot = app(SubscriptionPlans::class)->snapshot($current);
                $state = $snapshot['payment_method'] ?? [];
                $setup = $state['setup'] ?? [];
                if (
                    ($setup['expires_at'] ?? 0) <= now()->timestamp ||
                    (empty($setup['id']) && ($setup['expires_at'] ?? 0) < now()->addMinutes(30)->timestamp)
                ) {
                    $setup = ['key' => (string) Str::uuid(), 'expires_at' => now()->addHour()->timestamp];
                }
                $snapshot['payment_method'] = array_replace($state, ['customer_id' => $customer, 'setup' => $setup]);
                $current->update(['plan_snapshot' => $snapshot]);

                return $setup;
            });
            if (!empty($context['url'])) {
                return $context['url'];
            }
            $session = $payments->createPaymentMethodSetup(
                $subscription,
                $customer,
                $context['key'],
                $context['expires_at'],
            );
            DB::transaction(function () use ($subscription, $context, $session) {
                $current = Subscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
                $snapshot = $current->plan_snapshot;
                if (($snapshot['payment_method']['setup']['key'] ?? null) !== $context['key']) {
                    throw new HttpException(409);
                }
                $snapshot['payment_method']['setup'] = array_replace($context, $session);
                $current->update(['plan_snapshot' => $snapshot]);
            });

            return $session['url'];
        });
    }

    public function completePaymentMethod(Subscription $subscription, User $buyer, string $sessionId): void
    {
        if ($subscription->user_id !== $buyer->id) {
            abort(404);
        }
        if (($subscription->plan_snapshot['payment_method']['setup']['id'] ?? null) !== $sessionId) {
            throw new HttpException(422);
        }
        if (
            !$this->setupCompleted([
                'id' => $sessionId,
                'metadata' => [
                    'purpose' => 'subscription_payment_method',
                    'subscription_id' => (string) $subscription->id,
                ],
            ])
        ) {
            throw ValidationException::withMessages([
                'subscription' => __('shop::subscriptions.payment_method_unavailable'),
            ]);
        }
    }

    public function setupCompleted(array $event): bool
    {
        if (($event['metadata']['purpose'] ?? null) !== 'subscription_payment_method') {
            return false;
        }

        return app(OperationLock::class)->shared(
            fn () => DB::transaction(function () use ($event) {
                $subscription = Subscription::query()
                    ->where('provider', 'stripe')
                    ->whereKey($event['metadata']['subscription_id'] ?? null)
                    ->lockForUpdate()
                    ->firstOrFail();
                $snapshot = $subscription->plan_snapshot;
                $state = $snapshot['payment_method'] ?? [];
                $setup = $state['setup'] ?? [];
                $sessionId =
                    ($event['object'] ?? null) === 'setup_intent' ? $setup['id'] ?? null : $event['id'] ?? null;
                if (!is_string($sessionId) || ($setup['id'] ?? null) !== $sessionId) {
                    throw new HttpException(422);
                }
                if (!empty($setup['completed_at'])) {
                    return true;
                }
                if (!$this->canAddPaymentMethod($subscription)) {
                    return false;
                }
                $payments = app(StripeService::class);
                $session = $payments->paymentMethodSetup($sessionId);
                $intent = $session['setup_intent'] ?? [];
                $method = is_array($intent) ? $intent['payment_method'] ?? null : null;
                $customer = $state['customer_id'] ?? null;
                if (
                    !(
                        ($session['mode'] ?? null) === 'setup' &&
                        ($session['status'] ?? null) === 'complete' &&
                        ($session['client_reference_id'] ?? null) === (string) $subscription->id &&
                        ($session['metadata']['purpose'] ?? null) === 'subscription_payment_method' &&
                        ($session['metadata']['subscription_id'] ?? null) === (string) $subscription->id &&
                        ($session['metadata']['subscription_reference'] ?? null) ===
                            $subscription->provider_reference &&
                        ($session['metadata']['setup_key'] ?? null) === ($setup['key'] ?? null) &&
                        is_string($customer) &&
                        ($session['customer'] ?? null) === $customer &&
                        is_array($intent) &&
                        is_string($intent['id'] ?? null) &&
                        str_starts_with($intent['id'], 'seti_') &&
                        ($intent['status'] ?? null) === 'succeeded' &&
                        ($intent['customer'] ?? null) === $customer &&
                        is_array($method) &&
                        is_string($method['id'] ?? null) &&
                        str_starts_with($method['id'], 'pm_') &&
                        ($method['customer'] ?? null) === $customer
                    )
                ) {
                    throw new HttpException(422);
                }
                $remote = $payments->subscription($subscription->provider_reference);
                if (
                    ($remote['customer'] ?? null) !== $customer ||
                    ($remote['metadata']['order_reference'] ?? null) !== $subscription->order->reference
                ) {
                    throw new HttpException(422);
                }
                if (
                    ($remote['status'] ?? null) !== 'trialing' ||
                    ($remote['trial_end'] ?? 0) <= now()->timestamp ||
                    !empty($remote['cancel_at_period_end'])
                ) {
                    $this->synchronize($remote);

                    return false;
                }
                $updated = $payments->saveSubscriptionPaymentMethod(
                    $subscription->provider_reference,
                    $method['id'],
                    $sessionId,
                );
                if (($updated['customer'] ?? null) !== $customer) {
                    throw new HttpException(422);
                }
                $snapshot['payment_method']['saved'] = true;
                $snapshot['payment_method']['setup'] = [
                    'id' => $sessionId,
                    'key' => $setup['key'],
                    'completed_at' => now()->toIso8601String(),
                ];
                $subscription->update(['plan_snapshot' => $snapshot]);
                $this->synchronize($updated);

                return true;
            }),
        );
    }

    public function customerUpdated(array $eventCustomer): void
    {
        $reference = $eventCustomer['id'] ?? null;
        if (!is_string($reference) || !str_starts_with($reference, 'cus_')) {
            return;
        }
        $query = Subscription::query()
            ->where('provider', 'stripe')
            ->where('plan_snapshot->payment_method->customer_id', $reference);
        if (!(clone $query)->exists()) {
            return;
        }
        $payments = app(StripeService::class);
        $customer = !empty($eventCustomer['deleted']) ? $eventCustomer : $payments->customer($reference);
        $available = $payments->hasPaymentMethod(['customer' => $reference], $customer);
        $deleted = !empty($customer['deleted']);
        app(OperationLock::class)->shared(
            fn () => DB::transaction(function () use ($query, $available, $deleted) {
                foreach ((clone $query)->lockForUpdate()->get() as $subscription) {
                    $snapshot = $subscription->plan_snapshot;
                    $snapshot['payment_method']['saved'] =
                        !$deleted && (!empty($snapshot['payment_method']['subscription_saved']) || $available);
                    if ($deleted) {
                        $snapshot['payment_method']['subscription_saved'] = false;
                        $snapshot['payment_method']['deleted'] = true;
                    }
                    $subscription->update(['plan_snapshot' => $snapshot]);
                }
            }),
        );
    }

    public function canSubscribe(User $recipient, Product $product, bool $lock = false): bool
    {
        if (
            !$product->billing_interval ||
            (!$product->role_id && !$product->download_path) ||
            $product->wallet_credit_minor > 0 ||
            $product->uses_codes ||
            array_filter(json_decode($product->commands ?: '[]', true) ?: [])
        ) {
            return true;
        }

        $now = now();
        $existing = Order::query()
            ->join('shop__order_items', 'shop__order_items.order_id', '=', 'shop__orders.id')
            ->leftJoin('shop__subscriptions', 'shop__subscriptions.order_id', '=', 'shop__orders.id')
            ->leftJoin(
                'shop__subscription_trials',
                'shop__subscription_trials.subscription_id',
                '=',
                'shop__subscriptions.id',
            )
            ->leftJoin(
                'shop__subscription_payments',
                'shop__subscription_payments.subscription_id',
                '=',
                'shop__subscriptions.id',
            )
            ->leftJoin('shop__orders as paid_orders', 'paid_orders.id', '=', 'shop__subscription_payments.order_id')
            ->where('shop__orders.recurring', true)
            ->whereRaw('COALESCE(shop__order_items.recipient_id, shop__orders.user_id) = ?', [$recipient->id])
            ->where('shop__order_items.game', $product->game ?: app(GameRegistry::class)->activeId())
            ->when(
                $product->subscription_group,
                fn ($query) => $query->where('shop__order_items.subscription_group', $product->subscription_group),
                fn ($query) => $query->where('shop__order_items.product_id', $product->id),
            )
            ->where(function ($query) use ($now) {
                $query
                    ->where(function ($query) use ($now) {
                        $query->where('shop__subscription_payments.period_end', '>', $now)->usable('paid_orders');
                    })
                    ->orWhere(function ($query) use ($now) {
                        $query
                            ->whereNotNull('shop__subscriptions.id')
                            ->whereNotIn('shop__subscriptions.status', [
                                'canceled',
                                'cancelled',
                                'expired',
                                'incomplete_expired',
                            ])
                            ->where(function ($query) use ($now) {
                                $query
                                    ->where('shop__subscriptions.cancel_at_period_end', false)
                                    ->orWhere('shop__subscription_trials.ends_at', '>', $now)
                                    ->orWhere(function ($query) {
                                        $query
                                            ->whereNull('shop__subscriptions.paid_until')
                                            ->whereNull('shop__subscription_trials.ends_at');
                                    });
                            });
                    })
                    ->orWhere(function ($query) use ($now) {
                        $query
                            ->whereNull('shop__subscriptions.id')
                            ->where('shop__orders.status', 'pending')
                            ->whereNull('shop__orders.released_at')
                            ->where('shop__orders.financial_hold', false)
                            ->where('shop__orders.pricing_hold', false)
                            ->where('shop__orders.giftcard_hold', false)
                            ->where(function ($query) use ($now) {
                                $query
                                    ->where('shop__orders.auto_expire', false)
                                    ->orWhere('shop__orders.expires_at', '>', $now);
                            });
                    });
            })
            ->select('shop__orders.id')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();

        return $existing === null;
    }

    public function invoice(array $invoice, StripeService $payments): void
    {
        $operationLease = app(OperationLock::class)->acquireShared();
        try {
            $reference =
                $invoice['subscription'] ?? ($invoice['parent']['subscription_details']['subscription'] ?? null);
            $reference = is_array($reference) ? $reference['id'] ?? null : $reference;
            if (!$reference) {
                return;
            }
            $remote = $payments->subscription($reference) + ['id' => $reference];
            $original = $this->original('stripe', $remote['metadata']['order_reference'] ?? '');
            if (
                ($invoice['amount_paid'] ?? null) === 0 &&
                ($invoice['status'] ?? '') === 'paid' &&
                (($remote['status'] ?? '') === 'trialing' ||
                    (($invoice['billing_reason'] ?? null) === 'subscription_create' &&
                        ($invoice['amount_due'] ?? null) === 0 &&
                        $original->items()->where('trial_days', '>', 0)->exists()))
            ) {
                $this->synchronize($remote);

                return;
            }
            $payment = $payments->invoicePayment($invoice);
            $periodEnd = collect($invoice['lines']['data'] ?? [])->max(fn ($line) => $line['period']['end'] ?? 0);
            if (!$periodEnd) {
                throw new HttpException(422);
            }
            DB::transaction(function () use ($reference, $original, $invoice, $periodEnd, $remote, $payment) {
                $this->synchronize($remote);
                $subscription = Subscription::query()
                    ->where('provider', 'stripe')
                    ->where('provider_reference', $reference)
                    ->firstOrFail();
                $recorded = SubscriptionPayment::query()
                    ->where('provider_payment', 'stripe:'.$invoice['id'])
                    ->first();
                $change = $recorded
                    ? null
                    : app(SubscriptionPlans::class)->invoiceChange($subscription, $invoice, $remote);
                $plan = $change?->snapshot ?? app(SubscriptionPlans::class)->invoiceSnapshot($subscription, $invoice);
                $expected = $recorded
                    ? Order::query()->findOrFail($recorded->order_id)->total_minor
                    : ($change && $change->status !== 'scheduled'
                        ? $change->amount_minor
                        : $plan['unit_minor']);
                $this->record(
                    'stripe',
                    $reference,
                    $original,
                    $invoice['id'],
                    (int) $invoice['amount_paid'],
                    $invoice['currency'],
                    Carbon::createFromTimestamp($periodEnd),
                    $remote['status'],
                    $remote['cancel_at_period_end'] ?? false,
                    $payment,
                    $plan,
                    (int) $expected,
                    $change,
                );
                app(SubscriptionPlans::class)->reconcileOrder($original->id);
            }, 3);
        } finally {
            $operationLease->release();
        }
    }

    public function paypalPayment(array $resource, PayPalService $payments): void
    {
        $operationLease = app(OperationLock::class)->acquireShared();
        try {
            $reference = $resource['billing_agreement_id'] ?? null;
            if (!is_string($reference)) {
                throw new HttpException(422);
            }
            $remote = $payments->subscription($reference);
            $this->synchronize($remote, 'paypal');
            $original = $this->original('paypal', $remote['custom_id'] ?? '');
            if ($original->provider_reference !== $reference) {
                throw new HttpException(422);
            }
            $paidAt = Carbon::parse($resource['create_time']);
            $transactions = $payments->subscriptionTransactions(
                $reference,
                $paidAt->copy()->subDay(),
                $paidAt->copy()->addDay(),
            );
            $transaction = collect($transactions['transactions'] ?? [])->firstWhere('id', $resource['id']);
            if (!($transaction && ($transaction['status'] ?? '') === 'COMPLETED')) {
                throw new HttpException(422);
            }
            $amount = $transaction['amount_with_breakdown']['gross_amount'];
            $payment = new PaymentReference(
                $resource['id'],
                'sale',
                $payments->minor($amount['value']),
                $amount['currency_code'],
            );
            $item = OrderItem::query()->where('order_id', $original->id)->first();
            $periodEnd = Carbon::parse($transaction['time']);
            $periodEnd =
                $item->billing_interval === 'year' ? $periodEnd->addYearNoOverflow() : $periodEnd->addMonthNoOverflow();
            $this->record(
                'paypal',
                $reference,
                $original,
                $resource['id'],
                $payment->amount,
                $payment->currency,
                $periodEnd,
                strtolower($remote['status']),
                ($remote['status'] ?? '') === 'CANCELLED',
                $payment,
            );
        } finally {
            $operationLease->release();
        }
    }

    public function synchronize(array $remote, string $provider = 'stripe'): void
    {
        $operationLease = app(OperationLock::class)->acquireShared();
        try {
            $hasPaymentMethod =
                $provider === 'stripe' && is_string($remote['customer'] ?? null)
                    ? app(StripeService::class)->hasPaymentMethod($remote)
                    : null;
            $cancelled =
                $provider === 'stripe'
                    ? $remote['cancel_at_period_end'] ?? false
                    : in_array($remote['status'], ['CANCELLED', 'EXPIRED'], true);
            $orderReference =
                $provider === 'paypal' ? $remote['custom_id'] ?? null : $remote['metadata']['order_reference'] ?? null;
            if (is_string($orderReference) && $orderReference !== '') {
                $original = $this->original($provider, $orderReference);
                $item = OrderItem::query()->where('order_id', $original->id)->first();
                $snapshot = array_replace($item->getAttributes(), ['unit_minor' => $original->total_minor]);
                $price = $remote['items']['data'][0]['price'] ?? null;
                if ($provider === 'stripe' && $price) {
                    $snapshot['provider_price'] = is_array($price) ? $price['id'] : $price;
                }
                Subscription::query()->insertOrIgnore([
                    'provider' => $provider,
                    'provider_reference' => $remote['id'],
                    'order_id' => $original->id,
                    'user_id' => $original->user_id,
                    'product_id' => $item->product_id,
                    'status' => strtolower($remote['status']),
                    'plan_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                if ($provider === 'paypal') {
                    Order::query()
                        ->where('id', $original->id)
                        ->whereNull('provider_reference')
                        ->update(['provider_reference' => $remote['id']]);
                }
            }
            DB::transaction(function () use ($provider, $remote, $cancelled, $hasPaymentMethod) {
                $current = Subscription::query()
                    ->where('provider', $provider)
                    ->where('provider_reference', $remote['id'])
                    ->lockForUpdate()
                    ->first();
                if (!$current) {
                    return;
                }
                $values = [
                    'status' => strtolower($remote['status']),
                    'cancel_at_period_end' => $cancelled,
                    'updated_at' => now(),
                ];
                if ($provider === 'stripe' && is_string($remote['customer'] ?? null)) {
                    $snapshot = app(SubscriptionPlans::class)->snapshot($current);
                    $snapshot['payment_method'] = array_replace($snapshot['payment_method'] ?? [], [
                        'customer_id' => $remote['customer'],
                        'saved' => $hasPaymentMethod,
                        'subscription_saved' => !empty($remote['default_payment_method']) || !empty($remote['default_source']),
                    ]);
                    $values['plan_snapshot'] = $snapshot;
                }
                $current->update($values);
            });
            if ($provider === 'stripe' && strtolower($remote['status']) === 'trialing') {
                $subscription = Subscription::query()
                    ->where('provider', $provider)
                    ->where('provider_reference', $remote['id'])
                    ->first();
                if ($subscription) {
                    app(SubscriptionTrials::class)->activate($subscription, $remote);
                }
            }
            if ($provider === 'stripe') {
                $subscription = Subscription::query()
                    ->where('provider', $provider)
                    ->where('provider_reference', $remote['id'])
                    ->first();
                if ($subscription) {
                    app(SubscriptionPlans::class)->synchronize($subscription, $remote);
                }
            }
            if ($provider === 'paypal' && in_array($remote['status'], ['CANCELLED', 'EXPIRED'], true)) {
                $order = Order::query()
                    ->where('provider', 'paypal')
                    ->where('provider_reference', $remote['id'])
                    ->where('status', 'pending')
                    ->first();
                if ($order) {
                    app(OrderService::class)->cancel($order->id);
                }
            }
            if ($provider === 'stripe' && in_array($remote['status'], ['canceled', 'incomplete_expired'], true)) {
                $subscription = Subscription::query()
                    ->where('provider', $provider)
                    ->where('provider_reference', $remote['id'])
                    ->first();
                if ($subscription && !$subscription->order->paid_at && $subscription->order->status === 'pending') {
                    app(OrderService::class)->cancel($subscription->order_id);
                }
            }
        } finally {
            $operationLease->release();
        }
    }

    private function original(string $provider, string $reference): object
    {
        $original = Order::query()
            ->where('reference', $reference)
            ->where('provider', $provider)
            ->where('recurring', true)
            ->first();
        if ($original === null) {
            throw new HttpException(422);
        }

        return $original;
    }

    private function record(
        string $provider,
        string $reference,
        object $original,
        string $paymentId,
        int $amount,
        string $currency,
        Carbon $periodEnd,
        string $status,
        bool $cancelled,
        ?PaymentReference $payment = null,
        ?array $plan = null,
        ?int $expected = null,
        ?SubscriptionChange $change = null,
    ): void {
        if (!($amount === ($expected ?? $original->total_minor) && strtoupper($currency) === $original->currency)) {
            throw new HttpException(422);
        }
        $periodEnd = $periodEnd->copy()->utc();
        $orderId = DB::transaction(function () use (
            $provider,
            $reference,
            $original,
            $paymentId,
            $periodEnd,
            $status,
            $cancelled,
            $amount,
            $plan,
            $change,
        ) {
            User::query()->whereKey($original->user_id)->lockForUpdate()->firstOrFail();
            $original = Order::query()->where('id', $original->id)->lockForUpdate()->first();
            $item = OrderItem::query()->where('order_id', $original->id)->first();
            $subscription = Subscription::query()
                ->where('provider', $provider)
                ->where('provider_reference', $reference)
                ->lockForUpdate()
                ->first();
            if (!$subscription) {
                $id = Subscription::query()->insertGetId([
                    'provider' => $provider,
                    'provider_reference' => $reference,
                    'order_id' => $original->id,
                    'user_id' => $original->user_id,
                    'product_id' => $item->product_id,
                    'status' => $status,
                    'paid_until' => $periodEnd,
                    'cancel_at_period_end' => $cancelled,
                    'plan_snapshot' => json_encode($plan ?? $item->getAttributes(), JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $subscription = Subscription::query()->find($id);
            }
            $paymentKey = $provider.':'.$paymentId;
            $recorded = SubscriptionPayment::query()->where('provider_payment', $paymentKey)->first();
            if ($recorded) {
                return $recorded->order_id;
            }
            if (!$original->paid_at && !SubscriptionPayment::query()->where('order_id', $original->id)->exists()) {
                $orderId = $original->id;
            } else {
                $renewal = $original->getAttributes();
                unset($renewal['id']);
                $commercial = app(Commerce\ReceiptService::class)->configuration(
                    User::query()->findOrFail($original->user_id),
                );
                $originalCommercial = $original->commercial_snapshot ?? [];
                if (is_array($originalCommercial['billing'] ?? null)) {
                    $commercial['billing'] = $originalCommercial['billing'];
                }
                $renewal = array_merge($renewal, [
                    'reference' => (string) Str::uuid(),
                    'checkout_key' => (string) Str::uuid(),
                    'checkout_hash' => null,
                    'subtotal_minor' => $amount,
                    'discount_minor' => 0,
                    'upgrade_credit_minor' => 0,
                    'giftcard_minor' => 0,
                    'wallet_minor' => 0,
                    'total_minor' => $amount,
                    'status' => 'pending',
                    'provider_reference' => $paymentKey,
                    'payment_reference' => null,
                    'payment_kind' => null,
                    'refunded_minor' => 0,
                    'reversed_at' => null,
                    'pricing_hold' => false,
                    'pricing_review_reason' => null,
                    'financial_review' => (bool) $original->financial_hold,
                    'checkout_url' => null,
                    'paid_at' => null,
                    'released_at' => null,
                    'commercial_snapshot' => json_encode($commercial, JSON_THROW_ON_ERROR),
                    'expires_at' => now()->addDay(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $orderId = Order::query()->insertGetId($renewal);
                $snapshot = $plan ?? $item->getAttributes();
                unset(
                    $snapshot['id'],
                    $snapshot['provider_price'],
                    $snapshot['previous_plan'],
                    $snapshot['access_ends_at'],
                    $snapshot['payment_method'],
                );
                $snapshot['order_id'] = $orderId;
                $snapshot['unit_minor'] = $amount;
                $snapshot['paid_minor'] = $amount;
                $snapshot['trial_days'] = 0;
                OrderItem::query()->insert($snapshot);
            }
            $end = $periodEnd->toDateTimeString();
            SubscriptionPayment::query()->insert([
                'subscription_id' => $subscription->id,
                'order_id' => $orderId,
                'provider_payment' => $paymentKey,
                'period_end' => $end,
                'created_at' => now(),
            ]);
            $paidUntil =
                $subscription->paid_until && Carbon::parse($subscription->paid_until)->gt($periodEnd)
                    ? $subscription->paid_until
                    : $end;
            Subscription::query()
                ->where('id', $subscription->id)
                ->update([
                    'status' => $status,
                    'paid_until' => $paidUntil,
                    'cancel_at_period_end' => $cancelled,
                    'updated_at' => now(),
                ]);
            if ($change) {
                app(SubscriptionPlans::class)->apply($change, $subscription, $orderId);
            }

            return $orderId;
        }, 3);
        $accessEnd = isset($plan['access_ends_at'])
            ? min($periodEnd->timestamp, $plan['access_ends_at'])
            : $periodEnd->timestamp;
        app(OrderService::class)->complete(
            $orderId,
            $provider,
            $paymentId,
            $amount,
            $currency,
            Carbon::createFromTimestamp($accessEnd)->toDateTimeString(),
            $payment?->id,
            $payment?->kind,
        );
        if (isset($plan['access_ends_at'])) {
            Order::query()
                ->whereKey($orderId)
                ->where('status', 'paid')
                ->where('pricing_review_reason', 'fulfillment_failed')
                ->update(['pricing_review_reason' => 'subscription_superseded']);
        }
    }
}
