<?php

namespace KraftInk\Plugins\Shop\View\Composers;

use Illuminate\View\View;
use KraftInk\Plugins\Shop\Contracts\Payments\SupportsProducts;
use KraftInk\Plugins\Shop\View\CheckoutControls;
use App\Kraft\Wallet\Money;
use KraftInk\Plugins\Shop\Services\Payments\PaymentPolicy;
use KraftInk\Plugins\Shop\Services\Orders\SubscriptionService;

class PublicViews
{
    public function compose(View $view): void
    {
        $data = $view->getData();
        $view->with(
            'shopBuyerRoutePrefix',
            !empty($data['guest']) || !empty($data['order']->guest_id)
                ? 'plugins.shop.public.guests.'
                : 'plugins.shop.public.',
        );
        if (in_array($view->name(), ['shop::default.cart.index', 'shop::default.subscriptions.checkout'], true)) {
            if (auth()->check() && $data['products']->isNotEmpty()) {
                $view->with('checkoutControls', $this->checkout($data));
            }

            return;
        }
        if ($view->name() === 'shop::default.orders.show') {
            $view->with($this->order($data));

            return;
        }
        $view->with('subscriptionControls', $this->subscriptions($data));
    }

    private function checkout(array $data): CheckoutControls
    {
        $products = $data['products'];
        $gateways = $data['gateways'];
        $currency = $products->pluck('currency')->unique()->count() === 1 ? $products->first()->currency : '';
        $paymentPolicy = app(PaymentPolicy::class);
        $policies = [];
        $walletBalance = $data['walletBalance'] ?? '0.00';
        $walletCurrency = $data['walletCurrency'] ?? '';
        $canUseWallet = $data['canUseWallet'] ?? false;
        $hasSubscriptions = $products->contains(fn ($product) => $product->billing_interval !== null);
        $trialRequested = collect($data['options'] ?? [])->contains(
            fn ($options) => !empty($options['trial_requested']),
        );
        $requestedProvider = old('provider');
        $initialProvider = null;
        $coveredPolicy = null;
        $methods = [];
        $conditions = [];
        foreach ($gateways as $gateway) {
            $id = $gateway->id();
            $limits = $paymentPolicy->limits($id, $currency);
            $policy = $policies[$id] = [
                'allow_wallet' => $paymentPolicy->allowsWallet($id),
                'allow_giftcard' => $paymentPolicy->allowsGiftcard($id),
                'minimum' => $limits['minimum'] === null ? '' : Money::decimal($limits['minimum']),
                'maximum' => $limits['maximum'] === null ? '' : Money::decimal($limits['maximum']),
                'currency' => $currency,
            ];
            $capabilities = $gateway->capabilities();
            $supportsSubscriptions = !$hasSubscriptions || in_array('subscriptions', $capabilities, true);
            $supportsTrial = !$trialRequested || in_array('trials', $capabilities, true);
            $supportsProducts = !($gateway instanceof SupportsProducts) || $gateway->supportsProducts($products);
            $canSelect = $supportsSubscriptions && $supportsTrial && $supportsProducts;
            if (in_array('zero_total', $capabilities, true)) {
                if (!$hasSubscriptions && !$trialRequested && $canSelect && $coveredPolicy === null) {
                    $coveredPolicy = $policy;
                }

                continue;
            }
            if ($supportsSubscriptions) {
                $methods[$id] = [
                    'id' => $id,
                    'label' => $gateway->name().
                        ($canSelect
                            ? ''
                            : ' · '.
                                __(
                                    !$supportsTrial
                                        ? 'shop::subscriptions.trial_unsupported'
                                        : 'shop::commerce.wallet.unsupported_products',
                                )),
                    'canSelect' => $canSelect,
                    'selected' => false,
                ];
                if ($canSelect && ($initialProvider === null || $id === $requestedProvider)) {
                    $initialProvider = $id;
                }
            }
            $hasLimits = ($policy['minimum'] ?? '') !== '' || ($policy['maximum'] ?? '') !== '';
            if (!$hasLimits) {
                continue;
            }
            $messages = [];
            foreach (['minimum', 'maximum'] as $limit) {
                if (($policy[$limit] ?? '') !== '') {
                    $messages[] = __('shop::commerce.payments.policy.checkout_'.$limit, [
                        'amount' => $policy[$limit],
                        'currency' => $policy['currency'],
                    ]);
                }
            }
            $messages[] = __('shop::commerce.payments.policy.checkout_limits_help');
            $conditions[$id] = [
                'id' => $id,
                'canShow' => false,
                'messages' => $messages,
            ];
        }
        if ($coveredPolicy !== null) {
            $policies[''] = $coveredPolicy;
        }
        if ($initialProvider !== null) {
            $methods[$initialProvider]['selected'] = true;
            if (isset($conditions[$initialProvider])) {
                $conditions[$initialProvider]['canShow'] = true;
            }
        }
        $initialPolicy = $policies[$initialProvider ?? ''] ?? [];

        return new CheckoutControls(
            policies: $policies,
            methods: $initialProvider === null && $coveredPolicy !== null ? [] : array_values($methods),
            conditions: array_values($conditions),
            walletBalance: $walletBalance,
            walletCurrency: $walletCurrency,
            canUseWallet: $canUseWallet,
            canUseGiftcard: $data['acceptsGiftcard'],
            canUseCoupon: $data['acceptsCoupon'],
            canApplyWallet: $canUseWallet &&
                ($initialPolicy['allow_wallet'] ?? false) &&
                ($initialPolicy['currency'] ?? '') === $walletCurrency,
            canApplyGiftcard: $data['acceptsGiftcard'] &&
                (($initialPolicy['allow_giftcard'] ?? false) || ($policies['']['allow_giftcard'] ?? false)),
            canPay: ($initialProvider !== null || $coveredPolicy !== null) &&
                empty($data['productErrors']) &&
                empty($data['cartError']),
            requiresProvider: $hasSubscriptions,
        );
    }

    private function order(array $data): array
    {
        $order = $data['order'];
        $itemControls = [];
        foreach ($data['items'] as $item) {
            $itemControls[$item->id] = [
                'canDownload' => (!$item->recipient_id || $item->recipient_id === $order->user_id) && $item->canDownload(),
            ];
        }

        return [
            'canResume' => $order->isPayable() && $order->total_minor > 0 && $order->provider !== 'giftcard',
            'itemControls' => $itemControls,
        ];
    }

    private function subscriptions(array $data): array
    {
        $controls = [];
        foreach ($data['subscriptions'] as $subscription) {
            $statusKey = 'shop::messages.site.subscriptions.states.'.$subscription->status;
            $controls[$subscription->id] = [
                'canAddPaymentMethod' => ($subscription->provider ?? null) === 'stripe' &&
                    app(SubscriptionService::class)->canAddPaymentMethod($subscription),
                'canResumePayment' => ($subscription->provider ?? null) === 'stripe' &&
                    in_array($subscription->status, ['past_due', 'unpaid', 'incomplete'], true),
                'canCancel' => !$subscription->cancel_at_period_end &&
                    in_array(
                        $subscription->status,
                        ['active', 'past_due', 'trialing', 'suspended', 'approval_pending', 'unpaid', 'incomplete'],
                        true,
                    ),
                'cancellationScheduled' => (bool) $subscription->cancel_at_period_end,
                'statusLabel' => app('translator')->has($statusKey)
                    ? __($statusKey)
                    : __('shop::messages.site.subscriptions.states.unknown'),
            ];
        }

        return $controls;
    }
}
