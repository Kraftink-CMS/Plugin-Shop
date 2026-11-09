<?php

namespace KraftInk\Plugins\Shop\Services\Payments;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\Currencies\CurrencyRegistry;
use App\Kraft\Secrets;
use App\Kraft\System\OperationLock;
use App\Models\Accounts\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use KraftInk\Plugins\Shop\Contracts\Payments\DefaultAvailability;
use KraftInk\Plugins\Shop\Contracts\Payments\GuestCheckout;
use KraftInk\Plugins\Shop\Contracts\Payments\PaymentGateway;
use KraftInk\Plugins\Shop\Contracts\Payments\SupportsCurrencies;
use KraftInk\Plugins\Shop\Contracts\Payments\SupportsOrders;
use KraftInk\Plugins\Shop\Models\Orders\Order;
use KraftInk\Plugins\Shop\Services\GuestAuth;

class GatewayRegistry
{
    private array $gateways = [];

    public function register(string $id, string $class): void
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $id) || !is_subclass_of($class, PaymentGateway::class)) {
            throw new InvalidArgumentException('Invalid payment gateway registration.');
        }
        if (isset($this->gateways[$id]) && $this->gateways[$id] !== $class) {
            throw new InvalidArgumentException('A gateway with this identifier is already registered.');
        }
        $this->gateways[$id] = $class;
    }

    public function get(string $id): PaymentGateway
    {
        $class = $this->gateways[$id] ?? throw new InvalidArgumentException('Payment gateway unavailable.');
        $gateway = app($class);
        if ($gateway->id() !== $id) {
            throw new InvalidArgumentException('Payment gateway identifier mismatch.');
        }

        return $gateway;
    }

    public function all(): array
    {
        return array_map(fn ($class) => app($class), $this->gateways);
    }

    public function available(): array
    {
        return array_filter(
            $this->all(),
            fn (PaymentGateway $gateway) => $gateway->configured() && $this->enabled($gateway->id()),
        );
    }

    public function enabled(string $id): bool
    {
        $gateway = $this->get($id);
        $default = $gateway instanceof DefaultAvailability ? $gateway->defaultEnabled() : true;

        return $this->setting($id, 'enabled', $default ? '1' : '0') === '1';
    }

    public function supportsCurrency(string $id, string $code): bool
    {
        $currency = app(CurrencyRegistry::class)->find($code);
        if (!$currency) {
            return false;
        }
        $gateway = $this->get($id);

        return $gateway instanceof SupportsCurrencies
            ? $gateway->supportsCurrency($currency)
            : in_array($currency->code, CurrencyRegistry::DEFAULT_CODES, true);
    }

    public function setting(string $id, string $key, string $default = ''): string
    {
        return app(Secrets::class)->get('shop.payment.'.$id.'.'.$key, $default);
    }

    public function set(string $id, string $key, string $value): void
    {
        app(Secrets::class)->set('shop.payment.'.$id.'.'.$key, $value);
    }

    public function checkout(object $order, User $user): string
    {
        $operationLease = app(OperationLock::class)->acquireShared();
        try {
            return DB::transaction(function () use ($order, $user) {
                $order = Order::query()->where('id', $order->id)->lockForUpdate()->first();
                if (!($order && $order->user_id === $user->id && !$order->guest_id)) {
                    throw new HttpException(403);
                }
                if (!isset($this->available()[$order->provider])) {
                    throw new HttpException(422, __('shop::messages.errors.gateway_unavailable'));
                }
                $gateway = $this->get($order->provider);
                $localReplay = $order->isUsable() &&
                    $order->provider_reference &&
                    $order->checkout_url &&
                    in_array('internal_balance', $gateway->capabilities(), true);
                if (!$order->isPayable() && !$localReplay) {
                    throw new HttpException(422);
                }
                if (!$this->supportsCurrency($order->provider, $order->currency)) {
                    throw new HttpException(422, __('shop::commerce.payments.currencies.gateway_incompatible', ['currency' => $order->currency]));
                }
                if ($gateway instanceof SupportsOrders && !$gateway->supports($order)) {
                    throw new HttpException(422, __('shop::commerce.wallet.incompatible'));
                }
                if ($order->provider_reference && $order->checkout_url) {
                    return $order->checkout_url;
                }
                $session = $gateway->createCheckout($order, $user);
                $scheme = parse_url($session->url, PHP_URL_SCHEME);
                $local = parse_url($session->url, PHP_URL_HOST) === parse_url(config('app.url'), PHP_URL_HOST);
                if (!(strlen($session->reference) > 0 && strlen($session->reference) <= 180 && ($scheme === 'https' || $scheme === 'http' && $local))) {
                    throw new HttpException(502);
                }
                Order::query()
                    ->where('id', $order->id)
                    ->update(['provider_reference' => $session->reference, 'checkout_url' => $session->url]);

                return $session->url;
            });
        } finally {
            $operationLease->release();
        }
    }

    public function guestCheckout(object $order, object $guest): string
    {
        return app(OperationLock::class)->shared(function () use ($order, $guest) {
            return DB::transaction(function () use ($order, $guest) {
                $guest = app(GuestAuth::class)->buyer($guest->id, true);
                $order = Order::query()->where('id', $order->id)->lockForUpdate()->first();
                if ($order === null) {
                    abort(404);
                }
                if (!(!$order->user_id && ($order->guest_id ?? null) === $guest->id)) {
                    throw new HttpException(403);
                }
                if (!$order->isPayable()) {
                    throw new HttpException(422);
                }
                if (!isset($this->available()[$order->provider])) {
                    throw new HttpException(422, __('shop::messages.errors.gateway_unavailable'));
                }
                $gateway = $this->get($order->provider);
                if (!($gateway instanceof GuestCheckout && !$order->recurring)) {
                    throw new HttpException(422);
                }
                if (!$this->supportsCurrency($order->provider, $order->currency)) {
                    throw new HttpException(422);
                }
                if ($gateway instanceof SupportsOrders && !$gateway->supports($order)) {
                    throw new HttpException(422);
                }
                if ($order->provider_reference && $order->checkout_url) {
                    return $order->checkout_url;
                }
                $session = $gateway->createGuestCheckout($order, $guest);
                if (!(strlen($session->reference) > 0 && strlen($session->reference) <= 180 && parse_url($session->url, PHP_URL_SCHEME) === 'https')) {
                    throw new HttpException(502);
                }
                Order::query()
                    ->where('id', $order->id)
                    ->update([
                        'provider_reference' => $session->reference,
                        'checkout_url' => $session->url,
                    ]);

                return $session->url;
            });
        });
    }
}
