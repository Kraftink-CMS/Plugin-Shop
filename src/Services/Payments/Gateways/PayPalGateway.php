<?php

namespace KraftInk\Plugins\Shop\Services\Payments\Gateways;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\System\OperationLock;
use App\Models\Accounts\User;
use Illuminate\Http\Request;
use KraftInk\Plugins\Shop\Contracts\Payments\CancelsSubscriptions;
use KraftInk\Plugins\Shop\Contracts\Payments\GuestCheckout;
use KraftInk\Plugins\Shop\Contracts\Payments\PaymentGateway;
use KraftInk\Plugins\Shop\Contracts\Payments\RefundsPayments;
use KraftInk\Plugins\Shop\Contracts\Payments\ReviewsDisputes;
use KraftInk\Plugins\Shop\Contracts\Payments\VerifiesWebhooks;
use KraftInk\Plugins\Shop\Data\Payments\CheckoutSession;
use KraftInk\Plugins\Shop\Data\Payments\DisputeResult;
use KraftInk\Plugins\Shop\Data\Payments\PaymentReference;
use KraftInk\Plugins\Shop\Data\Payments\RefundResult;
use KraftInk\Plugins\Shop\Services\Orders\SubscriptionService;
use KraftInk\Plugins\Shop\Services\Payments\PayPalService;

class PayPalGateway implements CancelsSubscriptions, GuestCheckout, PaymentGateway, RefundsPayments, ReviewsDisputes, VerifiesWebhooks
{
    public function id(): string
    {
        return 'paypal';
    }

    public function name(): string
    {
        return 'PayPal';
    }

    public function capabilities(): array
    {
        return ['one_time', 'subscriptions', 'persistent_approval'];
    }

    public function configurationFields(): array
    {
        return [
            'client_id' => ['label' => 'shop::commerce.payments.settings.paypal_client_id', 'type' => 'secret'],
            'secret' => ['label' => 'shop::commerce.payments.settings.paypal_secret', 'type' => 'secret'],
            'webhook_id' => ['label' => 'shop::commerce.payments.settings.paypal_webhook_id', 'type' => 'secret'],
            'sandbox' => [
                'label' => 'shop::commerce.payments.settings.paypal_sandbox',
                'type' => 'boolean',
                'default' => '1',
            ],
        ];
    }

    public function configured(): bool
    {
        return app(PayPalService::class)->configured();
    }

    public function createCheckout(object $order, User $user): CheckoutSession
    {
        return app(PayPalService::class)->createCheckout($order, $user->email);
    }

    public function createGuestCheckout(object $order, object $guest): CheckoutSession
    {
        if ($order->recurring) {
            throw new HttpException(422);
        }

        return app(PayPalService::class)->createCheckout($order, $guest->email_address);
    }

    public function verifyWebhook(Request $request): array
    {
        return app(PayPalService::class)->verifyWebhook($request);
    }

    public function cancelSubscription(object $subscription): void
    {
        $operationLease = app(OperationLock::class)->acquireShared();
        try {
            app(SubscriptionService::class)->synchronize(
                app(PayPalService::class)->cancelSubscription($subscription->provider_reference),
                'paypal',
            );
        } finally {
            $operationLease->release();
        }
    }

    public function payment(object $order): PaymentReference
    {
        return app(PayPalService::class)->payment($order);
    }

    public function refund(PaymentReference $payment, object $refund): RefundResult
    {
        return app(PayPalService::class)->refund($payment, $refund);
    }

    public function getRefund(string $reference): RefundResult
    {
        return app(PayPalService::class)->getRefund($reference);
    }

    public function getDispute(string $reference): DisputeResult
    {
        return app(PayPalService::class)->getDispute($reference);
    }
}
