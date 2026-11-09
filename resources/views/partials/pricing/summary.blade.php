@use('KraftInk\Plugins\Shop\Services\Commerce\ReceiptService')
@if ($order->giftcard_hold)
    <div class="alert alert-warning" role="status">
        {{ __('shop::commerce.giftcards.funding_hold') }}
    </div>
@endif
@if ($order->pricing_hold)
    <div class="alert {{ $order->pricing_review_reason === 'subscription_superseded' ? 'alert-info' : 'alert-warning' }}"
        role="status">
        @if ($order->pricing_review_reason === 'subscription_superseded')
            {{ __('shop::subscriptions.superseded') }}
        @else
            {{ in_array($order->pricing_review_reason, ['late_payment', 'fulfillment_failed', 'processing'], true) ? __('shop::commerce.upgrades.payment_review') : __('shop::commerce.upgrades.funding_hold') }}
        @endif
    </div>
@endif
@if ($order->upgrade_credit_minor)
    <dl class="row">
        <dt class="col-sm-6">{{ __('shop::commerce.upgrades.credit') }}</dt>
        <dd class="col-sm-6">
            {{ ReceiptService::money($order->upgrade_credit_minor, $order->currency) }}
        </dd>
    </dl>
@endif
