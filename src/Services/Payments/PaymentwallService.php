<?php

namespace KraftInk\Plugins\Shop\Services\Payments;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\System\OperationLock;
use App\Kraft\Wallet\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use KraftInk\Plugins\Shop\Data\Payments\CheckoutSession;
use KraftInk\Plugins\Shop\Data\Payments\DisputeResult;
use KraftInk\Plugins\Shop\Models\Billing\FinancialEvent;
use KraftInk\Plugins\Shop\Models\Orders\Order;
use KraftInk\Plugins\Shop\Models\Orders\PaymentEvent;
use KraftInk\Plugins\Shop\Services\Finance\RefundService;
use KraftInk\Plugins\Shop\Services\Orders\OrderService;
use Symfony\Component\HttpFoundation\IpUtils;

class PaymentwallService
{
    public function configured(): bool
    {
        return $this->setting('project_key') !== '' && $this->setting('secret_key') !== '';
    }

    public function setting(string $key, string $default = ''): string
    {
        return app(GatewayRegistry::class)->setting('paymentwall', $key, $default);
    }

    public function createCheckout(object $order, string $buyer, string $email): CheckoutSession
    {
        if (!($this->configured() && !$order->recurring && $order->total_minor > 0)) {
            throw new HttpException(422);
        }
        $reference = $this->product($order);
        $parameters = [
            'key' => $this->setting('project_key'),
            'uid' => $this->customer($buyer),
            'widget' => 'p1_1',
            'ag_external_id' => $reference,
            'ag_name' => 'KraftInk '.$order->reference,
            'ag_type' => 'fixed',
            'amount' => Money::decimal($order->total_minor),
            'currencyCode' => $order->currency,
            'email' => $email,
            'success_url' => route(
                $order->guest_id ?? null ? 'plugins.shop.public.guests.orders.show' : 'plugins.shop.public.orders.show',
                $order->reference,
            ),
            'sign_version' => '3',
        ];
        if ($this->setting('sandbox', '1') === '1') {
            $parameters['ps'] = 'test';
        }
        $parameters['sign'] = $this->signature($parameters);

        return new CheckoutSession(
            $reference,
            'https://api.paymentwall.com/api/subscription?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986),
        );
    }

    public function verifyWebhook(Request $request): array
    {
        if (!$this->configured()) {
            throw new HttpException(503);
        }
        if (!IpUtils::checkIp($request->ip() ?? '', '216.127.71.0/24')) {
            throw new HttpException(401);
        }
        $raw = $request->isMethod('GET') ? (string) $request->server->get('QUERY_STRING', '') : $request->getContent();
        if (strlen($raw) > 131072) {
            throw new HttpException(422);
        }
        if ($raw !== '') {
            parse_str($raw, $parameters);
        } else {
            $parameters = $request->isMethod('GET') ? $request->query->all() : $request->request->all();
            $parameters = array_map(fn ($value) => $value === null ? '' : $value, $parameters);
        }
        if (count($parameters) > 64) {
            throw new HttpException(422);
        }
        foreach ($parameters as $key => $value) {
            if (!(is_string($key) && is_scalar($value) && strlen((string) $value) <= 2048)) {
                throw new HttpException(422);
            }
        }
        if (!(($parameters['sign_version'] ?? '') === '3' && is_string($parameters['sig'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $parameters['sig']))) {
            throw new HttpException(401);
        }
        $received = $parameters['sig'];
        unset($parameters['sig']);
        if (!hash_equals($this->signature($parameters), $received)) {
            throw new HttpException(401);
        }
        if (!(is_string($parameters['goodsid'] ?? null) && is_string($parameters['uid'] ?? null) && is_string($parameters['ref'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,140}$/D', $parameters['ref']) && in_array((string) ($parameters['type'] ?? ''), ['0', '2', '200', '201', '202', '203', '220'], true))) {
            throw new HttpException(422);
        }
        if (isset($parameters['is_test']) && (string) $parameters['is_test'] !== '1') {
            throw new HttpException(422);
        }
        if (!empty($parameters['is_test']) && $this->setting('sandbox', '1') !== '1') {
            throw new HttpException(401);
        }
        if (empty($parameters['is_test']) && $this->setting('sandbox', '1') === '1') {
            throw new HttpException(401);
        }

        return $parameters;
    }

    public function receive(Request $request): void
    {
        app(OperationLock::class)->shared(function () use ($request) {
            $parameters = $this->verifyWebhook($request);
            DB::transaction(function () use ($parameters) {
                $order = Order::query()
                    ->where('provider', 'paymentwall')
                    ->where('provider_reference', $parameters['goodsid'])
                    ->lockForUpdate()
                    ->first();
                if (!($order && $order->hasValidBuyer() && !$order->recurring && $parameters['goodsid'] === $this->product($order))) {
                    throw new HttpException(422);
                }
                $buyer = $order->user_id ? 'member:'.$order->user_id : 'guest:'.($order->guest_id ?? '');
                if (!hash_equals($this->customer($buyer), $parameters['uid'])) {
                    throw new HttpException(422);
                }
                $type = (string) $parameters['type'];
                if (isset($parameters['amount'])) {
                    $amount = Money::parse((string) $parameters['amount']);
                    if (!($type === '220' ? $amount > 0 && $amount <= $order->total_minor : $amount === $order->total_minor)) {
                        throw new HttpException(422);
                    }
                }
                if (isset($parameters['currency'])) {
                    if ($parameters['currency'] !== $order->currency) {
                        throw new HttpException(422);
                    }
                }
                $reference = $parameters['ref'];
                $eventOrder = PaymentEvent::query()
                    ->where('provider', 'paymentwall')
                    ->where('event_id', $reference)
                    ->value('order_id');
                $negativeOrder = FinancialEvent::query()
                    ->where('event_key', 'paymentwall:negative:'.$reference)
                    ->value('order_id');
                if ($eventOrder && $eventOrder !== $order->id || $negativeOrder && $negativeOrder !== $order->id) {
                    throw new HttpException(409);
                }
                if ($order->payment_reference && $order->payment_reference !== $reference) {
                    throw new HttpException(409);
                }
                if (in_array($type, ['0', '201'], true)) {
                    app(OrderService::class)->complete(
                        $order->id,
                        'paymentwall',
                        $reference,
                        $order->total_minor,
                        $order->currency,
                        null,
                        $reference,
                        'paymentwall',
                    );
                    $negative = FinancialEvent::query()
                        ->where('order_id', $order->id)
                        ->where('event_key', 'paymentwall:negative:'.$reference)
                        ->exists();
                    if ($negative) {
                        $this->reversal($order, $reference);
                    }

                    return;
                }
                if (in_array($type, ['2', '202'], true)) {
                    $key = 'paymentwall:negative:'.$reference;
                    if (!FinancialEvent::query()->where('event_key', $key)->exists()) {
                        FinancialEvent::query()->insert([
                            'order_id' => $order->id,
                            'event_key' => $key,
                            'type' => 'provider_reversal',
                            'reference' => $reference,
                            'status' => 'lost',
                            'amount_minor' => $order->total_minor,
                            'currency' => $order->currency,
                            'created_at' => now(),
                        ]);
                    }
                    Order::query()
                        ->where('id', $order->id)
                        ->update(['financial_hold' => true, 'updated_at' => now()]);
                    if ($order->paid_at) {
                        $this->reversal($order, $reference);
                    }

                    return;
                }
                if ($type === '220') {
                    Order::query()
                        ->where('id', $order->id)
                        ->update([
                            'financial_hold' => true,
                            'financial_review' => true,
                            'updated_at' => now(),
                        ]);
                }
            }, 3);
        });
    }

    public function signature(array $parameters): string
    {
        ksort($parameters, SORT_STRING);
        $message = '';
        foreach ($parameters as $key => $value) {
            $message .= $key.'='.(string) $value;
        }

        return hash('sha256', $message.$this->setting('secret_key'));
    }

    private function reversal(object $order, string $reference): void
    {
        app(RefundService::class)->recordDispute(
            'paymentwall',
            new DisputeResult('negative:'.$reference, $reference, $order->total_minor, $order->currency, 'lost'),
            'negative:'.$reference,
        );
    }

    private function customer(string $buyer): string
    {
        return hash_hmac('sha256', 'paymentwall:'.$buyer, config('app.key'));
    }

    private function product(object $order): string
    {
        return 'ki_'.
            $order->reference.
            '_'.
            substr(
                hash_hmac(
                    'sha256',
                    $order->reference.':'.$order->total_minor.':'.$order->currency,
                    config('app.key'),
                ),
                0,
                24,
            );
    }
}
