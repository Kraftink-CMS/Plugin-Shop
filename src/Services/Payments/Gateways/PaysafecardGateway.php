<?php

namespace KraftInk\Plugins\Shop\Services\Payments\Gateways;

use App\Models\Accounts\User;
use KraftInk\Plugins\Shop\Contracts\Payments\DefaultAvailability;
use KraftInk\Plugins\Shop\Contracts\Payments\GuestCheckout;
use KraftInk\Plugins\Shop\Contracts\Payments\PaymentGateway;
use KraftInk\Plugins\Shop\Contracts\Payments\SupportsOrders;
use KraftInk\Plugins\Shop\Data\Payments\CheckoutSession;
use KraftInk\Plugins\Shop\Services\Payments\PaysafecardService;

class PaysafecardGateway implements DefaultAvailability, GuestCheckout, PaymentGateway, SupportsOrders
{
    public function id(): string
    {
        return 'paysafecard';
    }

    public function name(): string
    {
        return 'Paysafecard';
    }

    public function capabilities(): array
    {
        return ['one_time'];
    }

    public function defaultEnabled(): bool
    {
        return false;
    }

    public function configurationFields(): array
    {
        return [
            'api_key' => ['label' => 'shop::commerce.payments.providers.paysafecard_key', 'type' => 'secret'],
            'sandbox' => [
                'label' => 'shop::commerce.payments.providers.sandbox',
                'type' => 'boolean',
                'default' => '1',
            ],
        ];
    }

    public function configured(): bool
    {
        return app(PaysafecardService::class)->configured();
    }

    public function supports(object $order): bool
    {
        return !$order->recurring && $order->total_minor > 0;
    }

    public function createCheckout(object $order, User $user): CheckoutSession
    {
        return app(PaysafecardService::class)->createCheckout($order, 'member:'.$user->id);
    }

    public function createGuestCheckout(object $order, object $guest): CheckoutSession
    {
        return app(PaysafecardService::class)->createCheckout($order, 'guest:'.$guest->id);
    }
}
