<?php

namespace KraftInk\Plugins\Shop\Services\Payments\Gateways;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\Currencies\Currency;
use App\Models\Accounts\User;
use KraftInk\Plugins\Shop\Contracts\Payments\PaymentGateway;
use KraftInk\Plugins\Shop\Contracts\Payments\SupportsCurrencies;
use KraftInk\Plugins\Shop\Data\Payments\CheckoutSession;

class GiftCardGateway implements PaymentGateway, SupportsCurrencies
{
    public function id(): string
    {
        return 'giftcard';
    }

    public function name(): string
    {
        return __('shop::messages.site.cart.giftcard_only');
    }

    public function capabilities(): array
    {
        return ['zero_total'];
    }

    public function configurationFields(): array
    {
        return [];
    }

    public function configured(): bool
    {
        return true;
    }

    public function supportsCurrency(Currency $currency): bool
    {
        return $currency->decimals === 2;
    }

    public function createCheckout(object $order, User $user): CheckoutSession
    {
        if ($order->total_minor !== 0) {
            throw new HttpException(422, __('shop::messages.errors.invalid_giftcard_insufficient'));
        }

        return new CheckoutSession(
            'internal-'.$order->reference,
            route('plugins.shop.public.orders.show', $order->reference),
        );
    }
}
