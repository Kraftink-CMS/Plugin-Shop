<?php

namespace KraftInk\Plugins\Shop\Services\Payments;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\System\OperationLock;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use KraftInk\Plugins\Shop\Data\Payments\CheckoutSession;
use KraftInk\Plugins\Shop\Models\Orders\Order;
use KraftInk\Plugins\Shop\Services\Orders\OrderService;

class PaysafecardService
{
    public function configured(): bool
    {
        return str_starts_with($this->setting('api_key'), 'psc_');
    }

    public function setting(string $key, string $default = ''): string
    {
        return app(GatewayRegistry::class)->setting('paysafecard', $key, $default);
    }

    public function createCheckout(object $order, string $buyer): CheckoutSession
    {
        if (!(!$order->recurring && $order->total_minor > 0)) {
            throw new HttpException(422);
        }
        $remote = $this->client()
            ->withHeaders(['Correlation-ID' => $order->reference])
            ->post($this->endpoint(), [
                'type' => 'PAYSAFECARD',
                'amount' => $order->total_minor / 100,
                'currency' => $order->currency,
                'customer' => ['id' => $this->customer($buyer)],
                'redirect' => [
                    'success_url' => route('plugins.shop.public.payments.paysafecard.return', $order->reference),
                    'failure_url' => route(
                        $order->guest_id ?? null
                            ? 'plugins.shop.public.guests.orders.show'
                            : 'plugins.shop.public.orders.show',
                        $order->reference,
                    ),
                ],
                'notification_url' => route('plugins.shop.webhooks.paysafecard.handle', [
                    'reference' => $order->reference,
                ]),
            ])
            ->throw()
            ->json();
        $this->assertPayment($remote, $order, $buyer);
        $url = $remote['redirect']['auth_url'] ?? '';
        $parts = is_string($url) ? parse_url($url) : false;
        $host = is_array($parts) ? strtolower($parts['host'] ?? '') : '';
        if (!(is_array($parts) && filter_var($url, FILTER_VALIDATE_URL) !== false && strlen($url) <= 8192 && !preg_match('/[\x00-\x20\x7f]/', $url) && ($parts['scheme'] ?? '') === 'https' && !isset($parts['user']) && !isset($parts['pass']) && (!isset($parts['port']) || $parts['port'] === 443) && (str_ends_with($host, '.paysafecard.com') || $host === 'paysafecard.com'))) {
            throw new HttpException(502);
        }

        return new CheckoutSession($remote['id'], $url);
    }

    public function settle(string $reference): bool
    {
        return app(OperationLock::class)->shared(function () use ($reference) {
            $payment = DB::transaction(function () use ($reference) {
                $order = Order::query()
                    ->where('reference', $reference)
                    ->where('provider', 'paysafecard')
                    ->lockForUpdate()
                    ->first();
                if (!($order && $order->hasValidBuyer() && is_string($order->provider_reference))) {
                    abort(404);
                }
                $buyer = $order->user_id ? 'member:'.$order->user_id : 'guest:'.($order->guest_id ?? '');
                $remote = $this->client()
                    ->get($this->endpoint().'/'.rawurlencode($order->provider_reference))
                    ->throw()
                    ->json();
                $this->assertPayment($remote, $order, $buyer);
                if ($remote['id'] !== $order->provider_reference) {
                    throw new HttpException(422);
                }
                if (($remote['status'] ?? '') === 'AUTHORIZED') {
                    $this->client()
                        ->withHeaders(['Correlation-ID' => $order->reference.'-capture'])
                        ->post($this->endpoint().'/'.rawurlencode($order->provider_reference).'/capture', [
                            'id' => $order->provider_reference,
                        ])
                        ->throw();
                    $remote = $this->client()
                        ->get($this->endpoint().'/'.rawurlencode($order->provider_reference))
                        ->throw()
                        ->json();
                    $this->assertPayment($remote, $order, $buyer);
                    if ($remote['id'] !== $order->provider_reference) {
                        throw new HttpException(422);
                    }
                }
                if (($remote['status'] ?? '') !== 'SUCCESS') {
                    return null;
                }

                return $order;
            }, 3);
            if (!$payment) {
                return false;
            }
            app(OrderService::class)->complete(
                $payment->id,
                'paysafecard',
                $payment->provider_reference,
                $payment->total_minor,
                $payment->currency,
                null,
                $payment->provider_reference,
                'paysafecard',
            );

            return true;
        });
    }

    private function assertPayment(array $payment, object $order, string $buyer): void
    {
        $amount = $payment['amount'] ?? null;
        if (!(is_string($payment['id'] ?? null) && preg_match('/^pay_[A-Za-z0-9_-]{1,175}$/D', $payment['id']) && ($payment['type'] ?? '') === 'PAYSAFECARD' && ($payment['currency'] ?? '') === $order->currency && (is_int($amount) || is_float($amount) || is_string($amount)) && is_numeric($amount) && (int) round((float) $amount * 100) === $order->total_minor && abs((float) $amount * 100 - $order->total_minor) < 1.0E-5 && ($payment['customer']['id'] ?? '') === $this->customer($buyer))) {
            throw new HttpException(422, __('shop::commerce.refunds.payment_mismatch'));
        }
    }

    private function customer(string $buyer): string
    {
        return hash_hmac('sha256', 'paysafecard:'.$buyer, config('app.key'));
    }

    private function endpoint(): string
    {
        return $this->setting('sandbox', '1') === '1'
            ? 'https://apitest.paysafecard.com/v1/payments'
            : 'https://api.paysafecard.com/v1/payments';
    }

    private function client(): PendingRequest
    {
        if (!$this->configured()) {
            throw new HttpException(503);
        }

        return Http::acceptJson()
            ->asJson()
            ->withHeaders(['Authorization' => 'Basic '.base64_encode($this->setting('api_key'))])
            ->connectTimeout(5)
            ->timeout(15)
            ->withoutRedirecting();
    }
}
