<?php

namespace KraftInk\Plugins\Shop\Services\Payments;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use KraftInk\Plugins\Shop\Data\Payments\CheckoutSession;
use KraftInk\Plugins\Shop\Data\Payments\DisputeResult;
use KraftInk\Plugins\Shop\Data\Payments\PaymentReference;
use KraftInk\Plugins\Shop\Data\Payments\RefundResult;
use KraftInk\Plugins\Shop\Models\Orders\Order;
use KraftInk\Plugins\Shop\Models\Orders\OrderItem;
use KraftInk\Plugins\Shop\Models\Orders\SubscriptionPayment;
use KraftInk\Plugins\Shop\Services\Orders\SubscriptionService;
use Illuminate\Http\Client\PendingRequest;
use SensitiveParameter;

class PayPalService
{
    private const array WEBHOOK_EVENTS = [
        'PAYMENT.CAPTURE.COMPLETED',
        'PAYMENT.SALE.COMPLETED',
        'PAYMENT.CAPTURE.REFUNDED',
        'PAYMENT.REFUND.PENDING',
        'PAYMENT.REFUND.FAILED',
        'PAYMENT.SALE.REFUNDED',
        'PAYMENT.CAPTURE.REVERSED',
        'PAYMENT.SALE.REVERSED',
        'BILLING.SUBSCRIPTION.CREATED',
        'BILLING.SUBSCRIPTION.ACTIVATED',
        'BILLING.SUBSCRIPTION.UPDATED',
        'BILLING.SUBSCRIPTION.EXPIRED',
        'BILLING.SUBSCRIPTION.CANCELLED',
        'BILLING.SUBSCRIPTION.SUSPENDED',
        'BILLING.SUBSCRIPTION.PAYMENT.FAILED',
        'CUSTOMER.DISPUTE.CREATED',
        'CUSTOMER.DISPUTE.UPDATED',
        'CUSTOMER.DISPUTE.RESOLVED',
    ];

    public function setting(string $key, string $default = ''): string
    {
        return app(GatewayRegistry::class)->setting('paypal', $key, $default);
    }

    public function configured(): bool
    {
        return (bool) $this->setting('client_id') &&
            (bool) $this->setting('secret') &&
            (bool) $this->setting('webhook_id');
    }

    public function validateWebhookCredentials(string $clientId, #[SensitiveParameter] string $secret, bool $sandbox): void
    {
        $this->authenticatedClient($this->base($sandbox), $clientId, $secret);
    }

    public function createCheckout(object $order, string $email): CheckoutSession
    {
        if ($order->provider_reference && $order->checkout_url) {
            return new CheckoutSession($order->provider_reference, $order->checkout_url);
        }
        if ($order->recurring) {
            return $this->createSubscription($order, $email);
        }
        $response = $this->client()
            ->withHeaders(['PayPal-Request-Id' => $order->reference])
            ->post($this->base().'/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [
                    [
                        'reference_id' => $order->reference,
                        'custom_id' => $order->reference,
                        'amount' => [
                            'currency_code' => $order->currency,
                            'value' => $this->decimal($order->total_minor),
                        ],
                    ],
                ],
                'payment_source' => [
                    'paypal' => [
                        'experience_context' => [
                            'return_url' => route(
                                $order->guest_id ?? null
                                    ? 'plugins.shop.public.guests.payments.paypal.return'
                                    : 'plugins.shop.public.payments.paypal.return',
                                $order->reference,
                            ),
                            'cancel_url' => route(
                                $order->guest_id ?? null
                                    ? 'plugins.shop.public.guests.orders.show'
                                    : 'plugins.shop.public.orders.show',
                                $order->reference,
                            ),
                            'user_action' => 'PAY_NOW',
                        ],
                    ],
                ],
            ])
            ->throw()
            ->json();
        $reference = $response['id'];
        $url =
            collect($response['links'])->firstWhere('rel', 'payer-action')['href'] ??
            (collect($response['links'])->firstWhere('rel', 'approve')['href'] ?? null);
        if (!(is_string($url) && parse_url($url, PHP_URL_SCHEME) === 'https' && in_array(parse_url($url, PHP_URL_HOST), ['www.paypal.com', 'www.sandbox.paypal.com'], true))) {
            throw new HttpException(502);
        }

        return new CheckoutSession($reference, $url);
    }

    public function configureWebhook(string $url, string $clientId, #[SensitiveParameter] string $secret, bool $sandbox): string
    {
        $base = $this->base($sandbox);
        $client = $this->authenticatedClient($base, $clientId, $secret);
        $endpoint = $base.'/v1/notifications/webhooks';
        $listed = $client->get($endpoint)->throw();
        $webhooks = $listed->json('webhooks');
        if (!$listed->ok() || !is_array($webhooks) || !array_is_list($webhooks)) {
            throw new HttpException(502);
        }

        $existing = null;
        foreach ($webhooks as $webhook) {
            $webhookUrl = $webhook['url'] ?? null;
            if (!is_string($webhookUrl) || $webhookUrl === '') {
                throw new HttpException(502);
            }
            if ($webhookUrl === $url) {
                $existing = $webhook;
                break;
            }
        }
        if ($existing === null) {
            $created = $client->post($endpoint, [
                'url' => $url,
                'event_types' => $this->webhookEventTypes(self::WEBHOOK_EVENTS),
            ])->throw();
            if (!$created->created()) {
                throw new HttpException(502);
            }

            return $this->confirmedWebhookId($created->json(), $url, self::WEBHOOK_EVENTS);
        }

        $id = $existing['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new HttpException(502);
        }
        $subscribed = $this->webhookEventNames($existing['event_types'] ?? null);
        if (!in_array('*', $subscribed, true) && ($missing = array_diff(self::WEBHOOK_EVENTS, $subscribed))) {
            $events = [...$subscribed, ...$missing];
            $updated = $client->patch($endpoint.'/'.rawurlencode($id), [[
                'op' => 'replace',
                'path' => '/event_types',
                'value' => $this->webhookEventTypes($events),
            ]])->throw();
            if (!$updated->ok()) {
                throw new HttpException(502);
            }

            return $this->confirmedWebhookId($updated->json(), $url, $events, $id);
        }

        return $id;
    }

    private function confirmedWebhookId(mixed $webhook, string $url, array $events, ?string $expectedId = null): string
    {
        $id = $webhook['id'] ?? null;
        if (!is_string($id) || $id === '' || ($expectedId !== null && $id !== $expectedId) || ($webhook['url'] ?? null) !== $url) {
            throw new HttpException(502);
        }
        $subscribed = $this->webhookEventNames($webhook['event_types'] ?? null);
        if (!in_array('*', $subscribed, true) && array_diff($events, $subscribed) !== []) {
            throw new HttpException(502);
        }

        return $id;
    }

    private function webhookEventNames(mixed $eventTypes): array
    {
        if (!is_array($eventTypes) || !array_is_list($eventTypes)) {
            throw new HttpException(502);
        }
        $names = array_column($eventTypes, 'name');
        if (count($names) !== count($eventTypes) || !array_all($names, fn ($name) => is_string($name))) {
            throw new HttpException(502);
        }

        return $names;
    }

    private function webhookEventTypes(array $names): array
    {
        return array_map(fn (string $name) => ['name' => $name], $names);
    }

    public function verifyWebhook(Request $request): array
    {
        if (!$this->configured()) {
            throw new HttpException(503);
        }
        $event = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $response = $this->client()
            ->post($this->base().'/v1/notifications/verify-webhook-signature', [
                'auth_algo' => $request->header('PAYPAL-AUTH-ALGO'),
                'cert_url' => $request->header('PAYPAL-CERT-URL'),
                'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID'),
                'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
                'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
                'webhook_id' => $this->setting('webhook_id'),
                'webhook_event' => $event,
            ])
            ->throw()
            ->json();
        if (($response['verification_status'] ?? '') !== 'SUCCESS') {
            throw new HttpException(401);
        }

        return $event;
    }

    public function capture(Order $order): array
    {
        if ($order->provider !== 'paypal' || !$order->provider_reference || $order->recurring || !$order->isPayable()) {
            throw new HttpException(422);
        }

        return $this->client()
            ->withHeaders(['PayPal-Request-Id' => $order->reference.'-capture'])
            ->withBody('{}', 'application/json')
            ->post($this->base().'/v2/checkout/orders/'.rawurlencode($order->provider_reference).'/capture')
            ->throw()
            ->json();
    }

    public function order(string $reference): array
    {
        return $this->client()
            ->get($this->base().'/v2/checkout/orders/'.rawurlencode($reference))
            ->throw()
            ->json();
    }

    public function subscription(string $reference): array
    {
        return $this->client()
            ->get($this->base().'/v1/billing/subscriptions/'.rawurlencode($reference))
            ->throw()
            ->json();
    }

    public function subscriptionTransactions(string $reference, Carbon $start, Carbon $end): array
    {
        return $this->client()
            ->get($this->base().'/v1/billing/subscriptions/'.rawurlencode($reference).'/transactions', [
                'start_time' => $start->utc()->toIso8601String(),
                'end_time' => $end->utc()->toIso8601String(),
            ])
            ->throw()
            ->json();
    }

    public function cancelSubscription(string $reference): array
    {
        $remote = $this->subscription($reference);
        if (!in_array($remote['status'], ['CANCELLED', 'EXPIRED'], true)) {
            $this->client()
                ->withHeaders(['PayPal-Request-Id' => 'cancel-'.$reference])
                ->post($this->base().'/v1/billing/subscriptions/'.rawurlencode($reference).'/cancel', [
                    'reason' => 'Cancellation requested by the account owner',
                ])
                ->throw();
            $remote = $this->subscription($reference);
        }
        if (!in_array($remote['status'], ['CANCELLED', 'EXPIRED'], true)) {
            throw new HttpException(502);
        }

        return $remote;
    }

    public function payment(object $order): PaymentReference
    {
        $reference = $order->payment_reference ?? null;
        if ($order->recurring) {
            if (!$reference) {
                $key = SubscriptionPayment::query()->where('order_id', $order->id)->value('provider_payment');
                if (!(is_string($key) && str_starts_with($key, 'paypal:'))) {
                    throw new HttpException(422);
                }
                $reference = substr($key, 7);
            }
            $sale = $this->client()
                ->get($this->base().'/v1/payments/sale/'.rawurlencode($reference))
                ->throw()
                ->json();
            if (!(($sale['id'] ?? null) === $reference && in_array($sale['state'] ?? '', ['completed', 'refunded', 'partially_refunded'], true))) {
                throw new HttpException(422);
            }

            return new PaymentReference(
                $reference,
                'sale',
                $this->minor($sale['amount']['total']),
                strtoupper($sale['amount']['currency']),
            );
        }
        if (!$reference) {
            $remote = $this->order($order->provider_reference);
            $captures = collect($remote['purchase_units'][0]['payments']['captures'] ?? []);
            if (!(($remote['id'] ?? null) === $order->provider_reference && $captures->count() === 1)) {
                throw new HttpException(422);
            }
            $reference = $captures->first()['id'];
        }
        $capture = $this->client()
            ->get($this->base().'/v2/payments/captures/'.rawurlencode($reference))
            ->throw()
            ->json();
        if (!(($capture['id'] ?? null) === $reference && in_array($capture['status'] ?? '', ['COMPLETED', 'REFUNDED', 'PARTIALLY_REFUNDED'], true))) {
            throw new HttpException(422);
        }

        return new PaymentReference(
            $reference,
            'capture',
            $this->minor($capture['amount']['value']),
            strtoupper($capture['amount']['currency_code']),
        );
    }

    public function refund(PaymentReference $payment, object $refund): RefundResult
    {
        $amount = $this->decimal($refund->external_minor);
        if ($payment->kind === 'sale') {
            $remote = $this->client()
                ->withHeaders(['PayPal-Request-Id' => $refund->request_key])
                ->post($this->base().'/v1/payments/sale/'.rawurlencode($payment->id).'/refund', [
                    'amount' => ['total' => $amount, 'currency' => $refund->currency],
                    'invoice_number' => $refund->id,
                ])
                ->throw()
                ->json();

            return $this->refundResult($remote, true);
        }
        $remote = $this->client()
            ->withHeaders(['PayPal-Request-Id' => $refund->request_key, 'Prefer' => 'return=representation'])
            ->post($this->base().'/v2/payments/captures/'.rawurlencode($payment->id).'/refund', [
                'amount' => ['value' => $amount, 'currency_code' => $refund->currency],
                'custom_id' => $refund->id,
                'note_to_payer' => $refund->reason,
            ])
            ->throw()
            ->json();

        return $this->refundResult($remote);
    }

    public function getRefund(string $reference): RefundResult
    {
        $sale = str_starts_with($reference, 'sale:');
        $id = $sale ? substr($reference, 5) : $reference;
        $path = $sale ? '/v1/payments/refund/' : '/v2/payments/refunds/';
        $result = $this->refundResult(
            $this->client()
                ->get($this->base().$path.rawurlencode($id))
                ->throw()
                ->json(),
            $sale,
        );
        if ($result->id !== $reference) {
            throw new HttpException(422);
        }

        return $result;
    }

    public function getDispute(string $reference): DisputeResult
    {
        if (str_starts_with($reference, 'reversal:')) {
            [$prefix, $kind, $payment] = array_pad(explode(':', $reference, 3), 3, '');
            if (!(in_array($kind, ['sale', 'capture'], true) && preg_match('/^[a-zA-Z0-9-]+$/D', $payment))) {
                throw new HttpException(422);
            }

            return $this->reversal($payment, $kind === 'sale');
        }
        $remote = $this->client()
            ->get($this->base().'/v1/customer/disputes/'.rawurlencode($reference))
            ->throw()
            ->json();
        if (($remote['dispute_id'] ?? '') !== $reference) {
            throw new HttpException(422);
        }
        $transactions = $remote['disputed_transactions'] ?? [];
        if (!(count($transactions) === 1 && isset($transactions[0]['seller_transaction_id']))) {
            throw new HttpException(422);
        }
        $outcome = $remote['dispute_outcome']['outcome_code'] ?? '';
        $status =
            ($remote['status'] ?? '') === 'RESOLVED'
                ? match ($outcome) {
                    'RESOLVED_SELLER_FAVOUR', 'CANCELED_BY_BUYER', 'DENIED' => 'won',
                    'RESOLVED_BUYER_FAVOUR', 'ACCEPTED' => 'lost',
                    default => 'review',
                }
        : 'open';

        return new DisputeResult(
            $remote['dispute_id'],
            $transactions[0]['seller_transaction_id'],
            $this->minor($remote['dispute_amount']['value']),
            strtoupper($remote['dispute_amount']['currency_code']),
            $status,
        );
    }

    public function reversal(string $reference, bool $sale): DisputeResult
    {
        $path = $sale ? '/v1/payments/sale/' : '/v2/payments/captures/';
        $remote = $this->client()
            ->get($this->base().$path.rawurlencode($reference))
            ->throw()
            ->json();
        if (($remote['id'] ?? '') !== $reference) {
            throw new HttpException(422);
        }
        $state = strtolower($sale ? $remote['state'] ?? '' : $remote['status'] ?? '');
        $status = in_array($state, ['refunded', 'reversed', 'declined', 'denied', 'failed'], true) ? 'lost' : 'review';

        return new DisputeResult(
            'reversal:'.($sale ? 'sale:' : 'capture:').$reference,
            $reference,
            $this->minor($remote['amount'][$sale ? 'total' : 'value']),
            strtoupper($remote['amount'][$sale ? 'currency' : 'currency_code']),
            $status,
        );
    }

    private function refundResult(array $remote, bool $sale = false): RefundResult
    {
        $status = strtolower($sale ? $remote['state'] ?? '' : $remote['status'] ?? '');
        $status = match ($status) {
            'completed' => 'succeeded',
            'failed', 'cancelled', 'canceled' => 'failed',
            default => 'pending',
        };
        $payment = $remote['sale_id'] ?? ($remote['capture_id'] ?? null);
        if (!$payment) {
            $up = collect($remote['links'] ?? [])->firstWhere('rel', 'up')['href'] ?? '';
            preg_match('~/payments/(?:captures|sale)/([a-zA-Z0-9-]+)(?:\?.*)?$~D', $up, $parts);
            $payment = $parts[1] ?? null;
        }
        if (!is_string($payment)) {
            throw new HttpException(422);
        }

        return new RefundResult(
            ($sale ? 'sale:' : '').$remote['id'],
            $payment,
            $this->minor($remote['amount'][$sale ? 'total' : 'value']),
            strtoupper($remote['amount'][$sale ? 'currency' : 'currency_code']),
            $status,
            $remote['custom_id'] ?? ($remote['invoice_number'] ?? null),
        );
    }

    private function createSubscription(object $order, string $email): CheckoutSession
    {
        $item = OrderItem::query()->where('order_id', $order->id)->first();
        $client = $this->client();
        $product = $client
            ->withHeaders(['PayPal-Request-Id' => $order->reference.'-product'])
            ->post($this->base().'/v1/catalogs/products', [
                'name' => $item->name,
                'type' => 'SERVICE',
                'category' => 'SOFTWARE',
            ])
            ->throw()
            ->json();
        $plan = $client
            ->withHeaders(['PayPal-Request-Id' => $order->reference.'-plan'])
            ->post($this->base().'/v1/billing/plans', [
                'product_id' => $product['id'],
                'name' => $item->name,
                'status' => 'ACTIVE',
                'billing_cycles' => [
                    [
                        'frequency' => ['interval_unit' => strtoupper($item->billing_interval), 'interval_count' => 1],
                        'tenure_type' => 'REGULAR',
                        'sequence' => 1,
                        'total_cycles' => 0,
                        'pricing_scheme' => [
                            'fixed_price' => [
                                'value' => $this->decimal($order->total_minor),
                                'currency_code' => $order->currency,
                            ],
                        ],
                    ],
                ],
                'payment_preferences' => ['auto_bill_outstanding' => false, 'payment_failure_threshold' => 1],
            ])
            ->throw()
            ->json();
        $remote = $client
            ->withHeaders(['PayPal-Request-Id' => $order->reference.'-subscription'])
            ->post($this->base().'/v1/billing/subscriptions', [
                'plan_id' => $plan['id'],
                'custom_id' => $order->reference,
                'subscriber' => ['email_address' => $email],
                'application_context' => [
                    'return_url' => route('plugins.shop.public.orders.show', $order->reference),
                    'cancel_url' => route('plugins.shop.public.orders.show', $order->reference),
                    'user_action' => 'SUBSCRIBE_NOW',
                    'shipping_preference' => 'NO_SHIPPING',
                ],
            ])
            ->throw()
            ->json();
        $url = collect($remote['links'] ?? [])->firstWhere('rel', 'approve')['href'] ?? null;
        if (!(is_string($url) && parse_url($url, PHP_URL_SCHEME) === 'https' && in_array(parse_url($url, PHP_URL_HOST), ['www.paypal.com', 'www.sandbox.paypal.com'], true))) {
            throw new HttpException(502);
        }
        $remote['custom_id'] = $order->reference;
        app(SubscriptionService::class)->synchronize($remote, 'paypal');

        return new CheckoutSession($remote['id'], $url);
    }

    public function minor(string $decimal): int
    {
        if (!preg_match('/^([0-9]{1,8})(?:\.([0-9]{1,2}))?$/', $decimal, $matches)) {
            throw new HttpException(422);
        }

        return (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    private function decimal(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    private function base(?bool $sandbox = null): string
    {
        $sandbox ??= $this->setting('sandbox', '1') === '1';

        return $sandbox ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
    }

    private function client(): PendingRequest
    {
        return $this->authenticatedClient($this->base(), $this->setting('client_id'), $this->setting('secret'));
    }

    private function authenticatedClient(string $base, string $clientId, #[SensitiveParameter] string $secret): PendingRequest
    {
        $response = Http::timeout(20)
            ->asForm()
            ->withBasicAuth($clientId, $secret)
            ->post($base.'/v1/oauth2/token', ['grant_type' => 'client_credentials'])
            ->throw();

        $token = $response->json('access_token');
        if (!is_string($token) || $token === '') {
            throw new HttpException(502);
        }

        return Http::timeout(20)->withToken($token)->acceptJson();
    }
}
