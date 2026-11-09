<?php

namespace KraftInk\Plugins\Shop\Http\Controllers;

use App\Kraft\System\SensitiveAction;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use KraftInk\Plugins\Shop\Models\Orders\Subscription;
use KraftInk\Plugins\Shop\Models\Catalog\Product;
use KraftInk\Plugins\Shop\Models\Orders\Order;
use KraftInk\Plugins\Shop\Models\Orders\SubscriptionChange;
use KraftInk\Plugins\Shop\Services\Orders\SubscriptionPlans;
use KraftInk\Plugins\Shop\Services\Orders\SubscriptionService;
use KraftInk\Plugins\Shop\Services\Orders\SubscriptionTrials;
use KraftInk\Plugins\Shop\Services\Orders\OrderService;
use KraftInk\Plugins\Shop\Services\Catalog\ProductOptions;
use KraftInk\Plugins\Shop\Services\Catalog\Catalog;
use KraftInk\Plugins\Shop\Services\Payments\GatewayRegistry;

class SubscriptionController
{
    public function prepare(Request $request, int $id): RedirectResponse
    {
        $product = $this->recurringProduct($request, $id);
        $request->validate(['quantity' => ['required', 'integer', Rule::in([1])]]);
        $options = app(ProductOptions::class)->fromRequest($request, $product);
        $previous = $request->session()->get('shop.subscription');
        $sameDraft =
            $previous &&
            $previous['product_id'] === $product->id &&
            app(ProductOptions::class)->equivalent($request->user(), $previous['options'], $options);
        $order = $sameDraft ? Order::query()->where('checkout_key', $previous['checkout_key'])->first() : null;
        $reuse =
            $sameDraft &&
            (!$order || ($order->status === 'pending' && (!$order->auto_expire || $order->expires_at->isFuture())));
        $request->session()->put('shop.subscription', [
            'product_id' => $product->id,
            'options' => $reuse && $order ? $previous['options'] : $options,
            'checkout_key' => $reuse ? $previous['checkout_key'] : (string) Str::uuid(),
        ]);
        if (!$request->user()) {
            redirect()->setIntendedUrl(route('plugins.shop.public.subscriptions.checkout'));

            return redirect()->route('login');
        }

        return redirect()->route('plugins.shop.public.subscriptions.checkout');
    }

    public function checkout(Request $request, GatewayRegistry $gateways): RedirectResponse|View
    {
        $draft = $this->draft($request);
        $order = Order::query()
            ->where('checkout_key', $draft['checkout_key'])
            ->where('user_id', $request->user()->id)
            ->first();
        if ($order) {
            return redirect()->route('plugins.shop.public.orders.show', $order->reference);
        }
        $product = app(Catalog::class)->localize($this->recurringProduct($request, $draft['product_id']));
        $options = $draft['options'];
        $productErrors = [];
        try {
            $options = app(ProductOptions::class)->validate($product, $options);
            $recipient = app(ProductOptions::class)->beneficiary($request->user(), $options);
            if (!Product::query()->visibleTo($recipient)->whereKey($product->id)->exists()) {
                $productErrors[$product->id] = __('shop::messages.errors.invalid_permission');
            } elseif (!app(SubscriptionService::class)->canSubscribe($recipient, $product)) {
                $productErrors[$product->id] = __('shop::subscriptions.already_subscribed');
            } elseif (
                $options['trial_requested'] &&
                !app(SubscriptionTrials::class)->eligible($request->user(), $recipient, $product)
            ) {
                $productErrors[$product->id] = __('shop::subscriptions.trial_ineligible');
            }
        } catch (ValidationException $exception) {
            $productErrors[$product->id] = $exception->validator->errors()->first();
        }
        $product->cart_price_minor = (int) ($options['price_minor'] ?? $product->price_minor);
        $product->cart_due_minor = !empty($options['trial_requested']) ? 0 : $product->cart_price_minor;
        $available = collect($gateways->available())->filter(
            fn ($gateway) => $gateways->supportsCurrency($gateway->id(), $product->currency),
        );

        return view('shop::default.subscriptions.checkout', [
            'product' => $product,
            'products' => collect([$product]),
            'cart' => [$product->id => 1],
            'options' => [$product->id => $options],
            'checkoutKey' => $draft['checkout_key'],
            'gateways' => $available,
            'productErrors' => $productErrors,
            'cartError' => null,
            'acceptsCoupon' => false,
            'acceptsGiftcard' => false,
            'canUseWallet' => false,
        ]);
    }

    public function store(Request $request, OrderService $orders, GatewayRegistry $gateways): RedirectResponse
    {
        $draft = $this->draft($request);
        $data = $request->validate([
            'provider' => ['required', Rule::in(array_keys($gateways->available()))],
            'checkout_key' => ['required', 'uuid', Rule::in([$draft['checkout_key']])],
            'quantity' => ['sometimes', 'integer', Rule::in([1])],
            'coupon' => ['prohibited'],
            'giftcard' => ['prohibited'],
            'wallet_amount' => ['prohibited'],
            'terms' => ['accepted'],
        ]);
        if (
            !Order::query()
                ->where('checkout_key', $draft['checkout_key'])
                ->where('user_id', $request->user()->id)
                ->exists()
        ) {
            $this->recurringProduct($request, $draft['product_id']);
        }
        $order = $orders->create(
            $request->user(),
            [$draft['product_id'] => 1],
            $data['provider'],
            $data['checkout_key'],
            options: [$draft['product_id'] => $draft['options']],
            billing: (array) $request->input('billing', []),
        );
        if (!$order->isPayable()) {
            return redirect()->route('plugins.shop.public.orders.show', $order->reference);
        }

        return redirect()->away($gateways->checkout($order, $request->user()));
    }

    public function index(Request $request, int $id, SubscriptionPlans $plans): View
    {
        $subscription = $this->owned($request, $id);
        $quote = $request->query('quote');
        $change = is_string($quote)
            ? $subscription->changes()->whereKey($quote)->firstOrFail()
            : $plans->pending($subscription);
        if (!$change && !$plans->available($subscription)) {
            abort(404);
        }

        return view('shop::default.subscriptions.change', [
            'subscription' => $subscription,
            'currentPlan' => $plans->snapshot($subscription),
            'products' => $plans->choices($subscription, $request->user()),
            'change' => $change,
        ]);
    }

    public function preview(Request $request, int $id, SubscriptionPlans $plans): RedirectResponse
    {
        $data = $request->validate(['product_id' => ['required', 'integer']]);
        $change = $plans->quote($this->owned($request, $id), (int) $data['product_id'], $request->user());

        return redirect()->route('plugins.shop.public.subscriptions.change', ['id' => $id, 'quote' => $change->id]);
    }

    public function payment(Request $request, int $id, SubscriptionPlans $plans): RedirectResponse
    {
        $url = $plans->payment($this->owned($request, $id), $request->user());

        return $url ? redirect()->away($url) : redirect()->route('plugins.shop.public.orders.index');
    }

    public function paymentMethod(Request $request, int $id, SubscriptionService $subscriptions): RedirectResponse
    {
        return redirect()->away($subscriptions->paymentMethod($this->owned($request, $id), $request->user()));
    }

    public function paymentMethodReturn(Request $request, int $id, SubscriptionService $subscriptions): RedirectResponse
    {
        $data = $request->validate(['session_id' => ['required', 'string', 'max:255']]);
        $subscriptions->completePaymentMethod($this->owned($request, $id), $request->user(), $data['session_id']);

        return redirect()
            ->route('plugins.shop.public.orders.index')
            ->with('status', __('shop::subscriptions.payment_method_saved'));
    }

    public function confirm(Request $request, int $id, string $change, SubscriptionPlans $plans): RedirectResponse
    {
        $subscription = $this->owned($request, $id);
        $change = SubscriptionChange::query()->where('subscription_id', $subscription->id)->findOrFail($change);
        $request->validate(['confirmed' => ['required', 'accepted']]);
        app(SensitiveAction::class)->confirm($request);
        $url = $plans->confirm($change, $request->user());

        return $url
            ? redirect()->away($url)
            : redirect()->route('plugins.shop.public.orders.index')->with('status', __('shop::subscriptions.saved'));
    }

    private function owned(Request $request, int $id): Subscription
    {
        return Subscription::query()->where('user_id', $request->user()->id)->findOrFail($id);
    }

    private function recurringProduct(Request $request, int $id): Product
    {
        return Product::query()->visibleTo($request->user())->whereNotNull('billing_interval')->findOrFail($id);
    }

    private function draft(Request $request): array
    {
        $draft = $request->session()->get('shop.subscription');
        if (!$draft) {
            abort(404);
        }

        return $draft;
    }
}
