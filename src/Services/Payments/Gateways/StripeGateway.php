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
use KraftInk\Plugins\Shop\Services\Payments\StripeService;

class StripeGateway implements CancelsSubscriptions, GuestCheckout, PaymentGateway, RefundsPayments, ReviewsDisputes, VerifiesWebhooks
{
    public function id(): string
    {
        return 'stripe';
    }

    public function name(): string
    {
        return 'Stripe';
    }

    public function capabilities(): array
    {
        return ['one_time', 'subscriptions', 'trials', 'subscription_changes'];
    }

    public function configurationFields(): array
    {
        return [
            'secret' => ['label' => 'shop::commerce.payments.settings.stripe_secret', 'type' => 'secret'],
            'webhook_secret' => [
                'label' => 'shop::commerce.payments.settings.stripe_webhook_secret',
                'type' => 'secret',
            ],
        ];
    }

    public function configured(): bool
    {
        return app(StripeService::class)->configured();
    }

    public function createCheckout(object $order, User $user): CheckoutSession
    {
        return app(StripeService::class)->createCheckout($order, $user->email);
    }

    public function createGuestCheckout(object $order, object $guest): CheckoutSession
    {
        if ($order->recurring) {
            throw new HttpException(422);
        }

        return app(StripeService::class)->createCheckout($order, $guest->email_address);
    }

    public function cancelSubscription(object $subscription): void
    {
        $operationLease = app(OperationLock::class)->acquireShared();
        try {
            if (
                $subscription
                    ->changes()
                    ->where('status', 'submitting')
                    ->where('amount_minor', 0)
                    ->whereNull('provider_reference')
                    ->exists()
            ) {
                $remote = app(StripeService::class)->subscription($subscription->provider_reference);
                if (!empty($remote['schedule'])) {
                    $reference = is_array($remote['schedule']) ? $remote['schedule']['id'] : $remote['schedule'];
                    $schedule = app(StripeService::class)->subscriptionSchedule($reference);
                    if (($schedule['subscription'] ?? null) !== $subscription->provider_reference) {
                        throw new HttpException(422);
                    }
                    app(StripeService::class)->releaseSchedule($reference, 'cancel-'.$subscription->id);
                }
            }
            foreach (
                $subscription
                    ->changes()
                    ->whereIn('status', ['submitting', 'scheduled'])
                    ->where('amount_minor', 0)
                    ->whereNotNull('provider_reference')
                    ->get() as $change
            ) {
                app(StripeService::class)->releaseSchedule($change->provider_reference, 'cancel-'.$subscription->id);
            }
            app(SubscriptionService::class)->synchronize(
                in_array($subscription->status, ['incomplete', 'unpaid'], true)
                    ? app(StripeService::class)->cancelUnpaidSubscription($subscription->provider_reference)
                    : app(StripeService::class)->cancelSubscription($subscription->provider_reference),
            );
            $subscription
                ->changes()
                ->whereIn('status', ['quoted', 'submitting', 'scheduled'])
                ->where('amount_minor', 0)
                ->update(['status' => 'cancelled']);
        } finally {
            $operationLease->release();
        }
    }

    public function verifyWebhook(Request $request): array
    {
        return app(StripeService::class)->verifyWebhook($request);
    }

    public function payment(object $order): PaymentReference
    {
        return app(StripeService::class)->payment($order);
    }

    public function refund(PaymentReference $payment, object $refund): RefundResult
    {
        return app(StripeService::class)->refund($payment, $refund);
    }

    public function getRefund(string $reference): RefundResult
    {
        return app(StripeService::class)->getRefund($reference);
    }

    public function getDispute(string $reference): DisputeResult
    {
        return app(StripeService::class)->getDispute($reference);
    }
}
