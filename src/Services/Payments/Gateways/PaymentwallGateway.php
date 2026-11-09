<?php

namespace KraftInk\Plugins\Shop\Services\Payments\Gateways;

use App\Models\Accounts\User;
use Illuminate\Http\Request;
use KraftInk\Plugins\Shop\Contracts\Payments\DefaultAvailability;
use KraftInk\Plugins\Shop\Contracts\Payments\GuestCheckout;
use KraftInk\Plugins\Shop\Contracts\Payments\PaymentGateway;
use KraftInk\Plugins\Shop\Contracts\Payments\SupportsOrders;
use KraftInk\Plugins\Shop\Contracts\Payments\VerifiesWebhooks;
use KraftInk\Plugins\Shop\Data\Payments\CheckoutSession;
use KraftInk\Plugins\Shop\Services\Payments\PaymentwallService;

class PaymentwallGateway implements DefaultAvailability, GuestCheckout, PaymentGateway, SupportsOrders, VerifiesWebhooks
{
    public function id(): string
    {
        return 'paymentwall';
    }

    public function name(): string
    {
        return 'Paymentwall';
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
            'project_key' => ['label' => 'shop::commerce.payments.providers.paymentwall_project', 'type' => 'secret'],
            'secret_key' => ['label' => 'shop::commerce.payments.providers.paymentwall_secret', 'type' => 'secret'],
            'sandbox' => [
                'label' => 'shop::commerce.payments.providers.sandbox',
                'type' => 'boolean',
                'default' => '1',
            ],
        ];
    }

    public function configured(): bool
    {
        return app(PaymentwallService::class)->configured();
    }

    public function supports(object $order): bool
    {
        return !$order->recurring && $order->total_minor > 0;
    }

    public function createCheckout(object $order, User $user): CheckoutSession
    {
        return app(PaymentwallService::class)->createCheckout($order, 'member:'.$user->id, $user->email);
    }

    public function createGuestCheckout(object $order, object $guest): CheckoutSession
    {
        return app(PaymentwallService::class)->createCheckout($order, 'guest:'.$guest->id, $guest->email_address);
    }

    public function verifyWebhook(Request $request): array
    {
        return app(PaymentwallService::class)->verifyWebhook($request);
    }
}
