@use('App\Kraft\Wallet\Money')
<section class="border-top pt-3 mb-3" aria-labelledby="checkout-summary-heading">
    <h3 class="h6" id="checkout-summary-heading">
        {{ $trialRequested ? __('shop::subscriptions.due_now') : __('shop::messages.site.cart.subtotal') }}</h3>
    @foreach ($products->groupBy('currency') as $currency => $items)
        <p class="fs-4 fw-semibold mb-2">
            {{ Money::format($items->sum(fn($item) => ($item->cart_due_minor ?? $item->cart_price_minor) * $cart[$item->id])) }}
            {{ $currency }}</p>
    @endforeach
    @foreach ($products->filter(fn($product) => $product->billing_interval) as $product)
        <p class="small text-body-secondary mb-2">
            {{ __(!empty($options[$product->id]['trial_requested']) ? 'shop::subscriptions.trial_after' : 'shop::subscriptions.trial_next', ['amount' => Money::format($product->cart_price_minor), 'currency' => $product->currency, 'interval' => __('shop::subscriptions.periods.' . $product->billing_interval), 'days' => $product->trial_days]) }}
        </p>
    @endforeach
    @if ($acceptsCoupon)
        <p class="small text-body-secondary mb-0">{{ __('shop::messages.site.cart.total_help') }}</p>
    @endif
</section>
