<?php

namespace KraftInk\Plugins\Shop\Services\Payments;

use App\Models\Accounts\User;
use ErrorException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use KraftInk\Plugins\Shop\Data\Payments\CheckoutSession;
use KraftInk\Plugins\Shop\Data\Payments\DisputeResult;
use KraftInk\Plugins\Shop\Data\Payments\PaymentReference;
use KraftInk\Plugins\Shop\Data\Payments\RefundResult;
use KraftInk\Plugins\Shop\Models\Orders\OrderItem;
use KraftInk\Plugins\Shop\Models\Orders\Subscription;
use KraftInk\Plugins\Shop\Models\Orders\SubscriptionPayment;
use Stripe\Exception\SignatureVerificationException;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;
use Stripe\Webhook;
use Stripe\WebhookSignature;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StripeService
{
    public const string API_VERSION = '2026-09-30.endive';

    public function setting(string $key, string $default = ''): string
    {
        return app(GatewayRegistry::class)->setting('stripe', $key, $default);
    }

    public function configured(): bool
    {
        return (bool) $this->setting('secret') && (bool) $this->setting('webhook_secret');
    }

    public function createCheckout(object $order, string $email): CheckoutSession
    {
        if ($order->provider_reference && $order->checkout_url) {
            return new CheckoutSession($order->provider_reference, $order->checkout_url);
        }
        $item = OrderItem::query()->where('order_id', $order->id)->first();
        $price = [
            'currency' => strtolower($order->currency),
            'unit_amount' => $order->total_minor,
            'product_data' => ['name' => $order->recurring ? $item->name : 'KraftInk '.$order->reference],
        ];
        if ($order->recurring) {
            $price['recurring'] = ['interval' => $item->billing_interval];
        }
        $payload = [
            'mode' => $order->recurring ? 'subscription' : 'payment',
            'integration_identifier' => 'kraftink_checkout_'.
                strtr(substr(str_replace('-', '', $order->reference), 0, 8), '0123456789', 'ghijklmnop'),
            'success_url' => route(
                $order->guest_id ?? null ? 'plugins.shop.public.guests.orders.show' : 'plugins.shop.public.orders.show',
                $order->reference,
            ),
            'cancel_url' => route(
                $order->guest_id ?? null ? 'plugins.shop.public.guests.orders.show' : 'plugins.shop.public.orders.show',
                $order->reference,
            ),
            'customer_email' => $email,
            'client_reference_id' => $order->reference,
            'expires_at' => Carbon::parse($order->expires_at)->subMinutes(5)->timestamp,
            'metadata' => ['order_reference' => $order->reference],
            'line_items' => [['price_data' => $price, 'quantity' => 1]],
        ];
        if ($order->recurring) {
            $customer = $this->checkoutCustomer($order);
            if ($customer !== null) {
                $payload['customer'] = $customer;
                unset($payload['customer_email']);
            }
            $payload['subscription_data'] = ['metadata' => ['order_reference' => $order->reference]];
            if ($item->trial_days > 0) {
                $payload['subscription_data']['trial_period_days'] = $item->trial_days;
                $payload['subscription_data']['trial_settings'] = [
                    'end_behavior' => ['missing_payment_method' => 'cancel'],
                ];
                $payload['payment_method_collection'] = $item->trial_requires_payment_method ? 'always' : 'if_required';
            }
        }
        $response = $this->client()->checkout->sessions->create($payload, ['idempotency_key' => $order->reference]);
        $reference = $response['id'];
        $url = $response['url'];
        if (!(parse_url($url, PHP_URL_SCHEME) === 'https' && parse_url($url, PHP_URL_HOST) === 'checkout.stripe.com')) {
            throw new HttpException(502);
        }

        return new CheckoutSession($reference, $url);
    }

    public function checkoutCustomerContext(User $payer): ?array
    {
        $established = Subscription::query()
            ->with('order')
            ->where('provider', 'stripe')
            ->where('user_id', $payer->id)
            ->whereNotNull('plan_snapshot->payment_method->customer_id')
            ->where(
                fn ($query) => $query
                    ->whereNull('plan_snapshot->payment_method->deleted')
                    ->orWhere('plan_snapshot->payment_method->deleted', false),
            )
            ->whereHas(
                'order',
                fn ($query) => $query->where('provider', 'stripe')->where('user_id', $payer->id)->whereNull('guest_id'),
            )
            ->orderByDesc('id')
            ->first();
        if ($established === null) {
            return null;
        }

        return [
            'id' => $established->plan_snapshot['payment_method']['customer_id'],
            'subscription_reference' => $established->provider_reference,
            'order_reference' => $established->order->reference,
        ];
    }

    private function checkoutCustomer(object $order): ?string
    {
        $context = $order->commercial_snapshot['stripe_customer'] ?? null;
        if ($context === null) {
            return null;
        }
        $established = Subscription::query()
            ->where('provider', 'stripe')
            ->where('provider_reference', $context['subscription_reference'])
            ->where('user_id', $order->user_id)
            ->whereHas(
                'order',
                fn ($query) => $query
                    ->where('provider', 'stripe')
                    ->where('reference', $context['order_reference'])
                    ->where('user_id', $order->user_id)
                    ->whereNull('guest_id'),
            )
            ->first();
        if ($established === null) {
            throw new HttpException(422);
        }
        $reference = $context['id'];
        $remote = $this->subscription($context['subscription_reference']);
        if (
            !(
                is_string($reference) &&
                str_starts_with($reference, 'cus_') &&
                ($remote['customer'] ?? null) === $reference &&
                ($remote['metadata']['order_reference'] ?? null) === $context['order_reference']
            )
        ) {
            throw new HttpException(422);
        }
        $customer = $this->customer($reference);
        if (!empty($customer['deleted'])) {
            throw new HttpException(422);
        }

        return $reference;
    }

    public function customer(string $reference): array
    {
        $customer = $this->client()->customers->retrieve($reference)->toArray();
        if (($customer['id'] ?? null) !== $reference) {
            throw new HttpException(422);
        }

        return $customer;
    }

    public function hasPaymentMethod(array $subscription, ?array $customer = null): bool
    {
        if (!empty($subscription['default_payment_method']) || !empty($subscription['default_source'])) {
            return true;
        }
        $reference = $subscription['customer'] ?? null;
        if (!is_string($reference) || !str_starts_with($reference, 'cus_')) {
            return false;
        }
        $customer ??= $this->customer($reference);
        if (($customer['id'] ?? null) !== $reference) {
            throw new HttpException(422);
        }

        return empty($customer['deleted']) &&
            (!empty($customer['invoice_settings']['default_payment_method']) || !empty($customer['default_source']));
    }

    public function verifyWebhook(Request $request): array
    {
        $secret = $this->setting('webhook_secret');
        if (!$secret) {
            throw new HttpException(503);
        }
        $body = $request->getContent();
        try {
            WebhookSignature::verifyHeader(
                $body,
                $request->header('Stripe-Signature', ''),
                $secret,
                Webhook::DEFAULT_TOLERANCE,
            );
        } catch (SignatureVerificationException|ErrorException) {
            throw new HttpException(401);
        }

        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    public function createPaymentMethodSetup(object $subscription, string $customer, string $key, int $expiresAt): array
    {
        $metadata = [
            'purpose' => 'subscription_payment_method',
            'subscription_id' => (string) $subscription->id,
            'subscription_reference' => $subscription->provider_reference,
            'setup_key' => $key,
        ];
        $session = $this->client()->checkout->sessions->create(
            [
                'mode' => 'setup',
                'integration_identifier' => 'kraftink_setup_'.strtr(substr(str_replace('-', '', $key), 0, 8), '0123456789', 'ghijklmnop'),
                'customer' => $customer,
                'currency' => strtolower($subscription->order->currency),
                'client_reference_id' => (string) $subscription->id,
                'metadata' => $metadata,
                'setup_intent_data' => ['metadata' => $metadata],
                'expires_at' => $expiresAt,
                'success_url' => route('plugins.shop.public.subscriptions.payment-method.return', $subscription->id).
                    '?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => route('plugins.shop.public.orders.index'),
            ],
            ['idempotency_key' => 'subscription-setup-'.$key],
        );
        if (
            !(
                is_string($session['id'] ?? null) &&
                str_starts_with($session['id'], 'cs_') &&
                is_string($session['url'] ?? null) &&
                parse_url($session['url'], PHP_URL_SCHEME) === 'https' &&
                parse_url($session['url'], PHP_URL_HOST) === 'checkout.stripe.com' &&
                is_int($session['expires_at'] ?? null)
            )
        ) {
            throw new HttpException(502);
        }

        return ['id' => $session['id'], 'url' => $session['url'], 'expires_at' => $session['expires_at']];
    }

    public function paymentMethodSetup(string $reference): array
    {
        $session = $this->client()->checkout->sessions
            ->retrieve($reference, [
                'expand' => ['setup_intent.payment_method'],
            ])
            ->toArray();
        if (($session['id'] ?? null) !== $reference) {
            throw new HttpException(422);
        }

        return $session;
    }

    public function saveSubscriptionPaymentMethod(string $subscription, string $method, string $session): array
    {
        $remote = $this->client()->subscriptions
            ->update(
                $subscription,
                ['default_payment_method' => $method],
                ['idempotency_key' => 'subscription-method-'.$session],
            )
            ->toArray();
        if (($remote['id'] ?? null) !== $subscription || ($remote['default_payment_method'] ?? null) !== $method) {
            throw new HttpException(422);
        }

        return $remote;
    }

    public function subscription(string $reference): array
    {
        $subscription = $this->client()->subscriptions->retrieve($reference)->toArray();
        if (($subscription['id'] ?? null) !== $reference) {
            throw new HttpException(422);
        }

        return $subscription;
    }

    public function invoice(string $reference): array
    {
        $invoice = $this->client()->invoices->retrieve($reference)->toArray();
        if (($invoice['id'] ?? null) !== $reference) {
            throw new HttpException(422);
        }

        return $invoice;
    }

    public function invoicePayment(array $invoice): PaymentReference
    {
        if (
            !(
                is_string($invoice['id'] ?? null) &&
                str_starts_with($invoice['id'], 'in_') &&
                ($invoice['status'] ?? '') === 'paid' &&
                empty($invoice['paid_out_of_band']) &&
                ($invoice['amount_paid_off_stripe'] ?? 0) === 0 &&
                ($invoice['amount_remaining'] ?? 0) === 0 &&
                is_int($invoice['amount_paid'] ?? null) &&
                $invoice['amount_paid'] > 0 &&
                is_string($invoice['currency'] ?? null)
            )
        ) {
            throw new HttpException(422);
        }
        $payments = $this->client()->invoicePayments
            ->all([
                'invoice' => $invoice['id'],
                'status' => 'paid',
                'limit' => 100,
            ])
            ->toArray();
        $paid = collect($payments['data'] ?? [])
            ->where('status', 'paid')
            ->values();
        if (!(empty($payments['has_more']) && $paid->count() === 1)) {
            throw new HttpException(422);
        }
        $allocation = $paid[0];
        if (
            !(
                ($allocation['invoice'] ?? null) === $invoice['id'] &&
                ($allocation['amount_paid'] ?? null) === $invoice['amount_paid'] &&
                ($allocation['currency'] ?? null) === $invoice['currency'] &&
                ($allocation['payment']['type'] ?? null) === 'payment_intent'
            )
        ) {
            throw new HttpException(422);
        }
        $reference = $allocation['payment']['payment_intent'] ?? null;
        if (!(is_string($reference) && str_starts_with($reference, 'pi_'))) {
            throw new HttpException(422);
        }
        $payment = $this->client()->paymentIntents->retrieve($reference)->toArray();
        if (
            !(
                ($payment['id'] ?? null) === $reference &&
                ($payment['status'] ?? null) === 'succeeded' &&
                ($payment['amount_received'] ?? null) === $invoice['amount_paid'] &&
                ($payment['currency'] ?? null) === $invoice['currency']
            )
        ) {
            throw new HttpException(422);
        }

        return new PaymentReference(
            $reference,
            'payment_intent',
            $payment['amount_received'],
            strtoupper($payment['currency']),
        );
    }

    public function cancelSubscription(string $reference): array
    {
        return $this->client()->subscriptions
            ->update($reference, ['cancel_at_period_end' => true], ['idempotency_key' => 'cancel-'.$reference])
            ->toArray();
    }

    public function cancelUnpaidSubscription(string $reference): array
    {
        $remote = $this->subscription($reference);
        if (($remote['status'] ?? '') === 'canceled') {
            return $remote;
        }
        if (!in_array($remote['status'] ?? '', ['incomplete', 'unpaid'], true)) {
            return $this->cancelSubscription($reference);
        }

        return $this->client()->subscriptions
            ->cancel($reference, ['invoice_now' => false, 'prorate' => false])
            ->toArray();
    }

    public function subscriptionPrice(object $product, string $key): string
    {
        $price = $this->client()->prices->create(
            [
                'currency' => strtolower($product->currency),
                'unit_amount' => $product->price_minor,
                'recurring' => ['interval' => $product->billing_interval],
                'product_data' => ['name' => $product->name],
            ],
            ['idempotency_key' => 'subscription-price-'.$key],
        );
        if (!(is_string($price['id'] ?? null) && str_starts_with($price['id'], 'price_'))) {
            throw new HttpException(502);
        }

        return $price['id'];
    }

    public function previewSubscription(array $remote, string $price, int $date): array
    {
        $item = $this->subscriptionItem($remote);

        return $this->client()->invoices
            ->createPreview([
                'subscription' => $remote['id'],
                'subscription_details' => [
                    'items' => [['id' => $item['id'], 'price' => $price, 'quantity' => 1]],
                    'proration_date' => $date,
                    'proration_behavior' => 'always_invoice',
                ],
            ])
            ->toArray();
    }

    public function changeSubscription(array $remote, string $price, int $date, string $key): array
    {
        $item = $this->subscriptionItem($remote);

        return $this->client()->subscriptions
            ->update(
                $remote['id'],
                [
                    'items' => [['id' => $item['id'], 'price' => $price, 'quantity' => 1]],
                    'proration_date' => $date,
                    'proration_behavior' => 'always_invoice',
                    'payment_behavior' => 'pending_if_incomplete',
                    'expand' => ['latest_invoice'],
                ],
                ['idempotency_key' => 'subscription-change-'.$key],
            )
            ->toArray();
    }

    public function createSubscriptionSchedule(array $remote, string $key): array
    {
        $schedule = $this->client()->subscriptionSchedules->create(
            ['from_subscription' => $remote['id']],
            ['idempotency_key' => 'subscription-schedule-'.$key],
        );
        if (!(is_string($schedule['id'] ?? null) && str_starts_with($schedule['id'], 'sub_sched_'))) {
            throw new HttpException(502);
        }

        return $schedule->toArray();
    }

    public function subscriptionSchedule(string $reference): array
    {
        $schedule = $this->client()->subscriptionSchedules->retrieve($reference)->toArray();
        if (($schedule['id'] ?? null) !== $reference) {
            throw new HttpException(422);
        }

        return $schedule;
    }

    public function scheduleSubscription(
        array $remote,
        array $schedule,
        string $price,
        int $end,
        string $interval,
        string $key,
    ): array {
        $item = $this->subscriptionItem($remote);
        if (($schedule['subscription'] ?? null) !== $remote['id']) {
            throw new HttpException(422);
        }

        return $this->client()->subscriptionSchedules
            ->update(
                $schedule['id'],
                [
                    'end_behavior' => 'release',
                    'metadata' => ['kraftink_change' => $key],
                    'proration_behavior' => 'none',
                    'phases' => [
                        [
                            'start_date' => $schedule['current_phase']['start_date'],
                            'end_date' => $end,
                            'items' => [
                                [
                                    'price' => is_array($item['price']) ? $item['price']['id'] : $item['price'],
                                    'quantity' => 1,
                                ],
                            ],
                            'proration_behavior' => 'none',
                        ],
                        [
                            'start_date' => $end,
                            'duration' => ['interval' => $interval, 'interval_count' => 1],
                            'items' => [['price' => $price, 'quantity' => 1]],
                            'proration_behavior' => 'none',
                        ],
                    ],
                ],
                ['idempotency_key' => 'subscription-phases-'.$key],
            )
            ->toArray();
    }

    public function releaseSchedule(string $reference, string $key): void
    {
        $this->client()->subscriptionSchedules->release($reference, [], [
            'idempotency_key' => 'subscription-release-'.$key,
        ]);
    }

    public function subscriptionItem(array $remote): array
    {
        $items = $remote['items']['data'] ?? [];
        if (
            !(
                count($items) === 1 &&
                is_string($items[0]['id'] ?? null) &&
                ($items[0]['quantity'] ?? 1) === 1 &&
                !empty($items[0]['price'])
            )
        ) {
            throw new HttpException(422);
        }

        return $items[0];
    }

    public function payment(object $order): PaymentReference
    {
        $reference = $order->payment_reference ?? null;
        if (!$reference && $order->recurring) {
            $invoice = SubscriptionPayment::query()->where('order_id', $order->id)->value('provider_payment');
            if (!(is_string($invoice) && str_starts_with($invoice, 'stripe:in_'))) {
                throw new HttpException(422);
            }
            $payments = $this->client()->invoicePayments
                ->all(['invoice' => substr($invoice, 7), 'limit' => 100])
                ->toArray();
            $paid = collect($payments['data'] ?? [])
                ->where('status', 'paid')
                ->values();
            if (
                !(
                    empty($payments['has_more']) &&
                    $paid->count() === 1 &&
                    ($paid[0]['payment']['type'] ?? '') === 'payment_intent'
                )
            ) {
                throw new HttpException(422);
            }
            $reference = $paid[0]['payment']['payment_intent'];
        }
        if (!$reference) {
            $session = $this->client()->checkout->sessions->retrieve($order->provider_reference);
            if (
                !(
                    ($session['client_reference_id'] ?? null) === $order->reference &&
                    ($session['payment_status'] ?? '') === 'paid'
                )
            ) {
                throw new HttpException(422);
            }
            $reference = $session['payment_intent'] ?? null;
        }
        if (!(is_string($reference) && str_starts_with($reference, 'pi_'))) {
            throw new HttpException(422);
        }
        $payment = $this->client()->paymentIntents->retrieve($reference);
        if (!(($payment['id'] ?? null) === $reference && ($payment['status'] ?? '') === 'succeeded')) {
            throw new HttpException(422);
        }

        return new PaymentReference(
            $reference,
            'payment_intent',
            (int) $payment['amount_received'],
            strtoupper($payment['currency']),
        );
    }

    public function refund(PaymentReference $payment, object $refund): RefundResult
    {
        $remote = $this->client()->refunds->create(
            [
                'payment_intent' => $payment->id,
                'amount' => $refund->external_minor,
                'metadata' => ['kraftink_refund' => $refund->id],
                'reason' => 'requested_by_customer',
            ],
            ['idempotency_key' => $refund->request_key],
        );

        return $this->refundResult($remote->toArray());
    }

    public function getRefund(string $reference): RefundResult
    {
        $result = $this->refundResult($this->client()->refunds->retrieve($reference)->toArray());
        if ($result->id !== $reference) {
            throw new HttpException(422);
        }

        return $result;
    }

    public function getDispute(string $reference): DisputeResult
    {
        $remote = $this->client()->disputes->retrieve($reference);
        if (($remote['id'] ?? '') !== $reference) {
            throw new HttpException(422);
        }
        $payment = $remote['payment_intent'] ?? null;
        if (!$payment) {
            $charge = $this->client()->charges->retrieve($remote['charge']);
            $payment = $charge['payment_intent'] ?? null;
        }
        if (!is_string($payment)) {
            throw new HttpException(422);
        }
        $status = match ($remote['status'] ?? '') {
            'won', 'warning_closed' => 'won',
            'lost' => 'lost',
            default => 'open',
        };

        return new DisputeResult(
            $remote['id'],
            $payment,
            (int) $remote['amount'],
            strtoupper($remote['currency']),
            $status,
        );
    }

    private function refundResult(array $remote): RefundResult
    {
        $status = match ($remote['status'] ?? '') {
            'succeeded' => 'succeeded',
            'failed', 'canceled' => 'failed',
            default => 'pending',
        };

        return new RefundResult(
            $remote['id'],
            $remote['payment_intent'],
            (int) $remote['amount'],
            strtoupper($remote['currency']),
            $status,
            $remote['metadata']['kraftink_refund'] ?? null,
        );
    }

    private function client(): StripeClient
    {
        CurlClient::instance()->setTimeout(20);

        return new StripeClient([
            'api_key' => $this->setting('secret'),
            'stripe_version' => self::API_VERSION,
            'max_network_retries' => 0,
        ]);
    }
}
