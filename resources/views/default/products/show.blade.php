@use('App\Kraft\Wallet\Money')
@use('KraftInk\Plugins\Shop\Services\Catalog\ProductOptions')
@extends('default.layouts.layout')
@php
    $editingPurchase = $editingCart || $editingSubscription;
    $quantityLimit = $editingPurchase ? $product->quantityLimit() : $addQuantityLimit;
    $hasTrial = $trialEligible;
    $trialRequested = (bool) old('trial_requested', $options['trial_requested'] ?? false);
    $primaryTrial = $editingPurchase ? $trialRequested : $hasTrial;
    $secondaryTrial = $primaryTrial ? false : (($product->trial_days ?? 0) > 0 ? true : null);
    $purchaseLabel = $editingPurchase
        ? __('shop::messages.actions.update')
        : ($hasTrial
            ? __('shop::subscriptions.try_free')
            : ($product->billing_interval
                ? __('shop::subscriptions.subscribe')
                : __('shop::messages.site.catalog.add_to_cart')));
    $unavailableReason = __(
        $product->quantityLimit() === 0
            ? 'shop::messages.products.options.unavailable'
            : 'shop::messages.products.options.cart_limit',
    );
@endphp
@section('content')
    <article class="mb-4">
        <nav class="page-heading mb-4">
            <a class="text-decoration-none" href="{{ route('plugins.shop.public.catalog.index') }}"><i
                    class="bi bi-arrow-left me-2" aria-hidden="true"></i>{{ __('shop::admin.catalog.back') }}</a>
        </nav>
        <div class="row g-4 align-items-start">
            @if ($image)
                <div class="col-md-5">
                    <img class="img-fluid rounded border w-100" src="{{ route('site.media.show', $image) }}"
                        alt="{{ $image->displayText('alt') ?: $product->name }}" width="{{ $image->width }}"
                        height="{{ $image->height }}">
                </div>
            @endif
            <div class="{{ $image ? 'col-md-7' : 'col-12 col-lg-10 col-xl-9 mx-auto' }}">
                <div class="card card-body p-4">
                    <h1 class="h2 mb-3">{{ $product->name }}</h1>
                    <div class="prose text-body-secondary mb-0">
                        {!! $product->description !!}
                    </div>
                    @include('shop::default.commerce.partials.tax_note')
                    <form data-ajax="true" data-redirect-url="response" method="post"
                        action="{{ $product->billing_interval ? route('plugins.shop.public.subscriptions.prepare', $product->id) : route('plugins.shop.public.cart.update', $product->id) }}">
                        @csrf
                        @if (!$product->billing_interval)
                            <input type="hidden" name="intent" value="{{ $editingCart ? 'set' : 'add' }}">
                        @endif
                        @if (($product->kind ?? 'product') === 'giftcard')
                            <div class="mb-3">
                                <label class="form-label"
                                    for="giftcard-recipient">{{ __('shop::commerce.giftcards.recipient') }}</label>
                                <input class="form-control" id="giftcard-recipient" name="giftcard_recipient"
                                    value="{{ old('giftcard_recipient', $options['giftcard_recipient'] ?? '') }}"
                                    autocomplete="off" maxlength="40" minlength="2" pattern="[A-Za-z0-9][A-Za-z0-9_.-]*">
                                <p class="form-text">{{ __('shop::commerce.giftcards.recipient_help') }}</p>
                            </div>
                        @endif
                        @if ($product->custom_price)
                            <div class="mb-3">
                                <label class="form-label"
                                    for="product-price">{{ __('shop::messages.products.options.your_price', ['currency' => $product->currency]) }}</label>
                                <input class="form-control" id="product-price" name="price_amount" inputmode="decimal"
                                    value="{{ old('price_amount', Money::decimal($options['price_minor'] ?? ($product->minimum_price_minor ?? $product->price_minor))) }}"
                                    required>
                                <p class="form-text">
                                    {{ __('shop::messages.products.options.price_range', ['min' => Money::format($product->minimum_price_minor ?? $product->price_minor), 'max' => Money::format($product->maximum_price_minor ?? 99999999), 'currency' => $product->currency]) }}
                                </p>
                            </div>
                        @endif
                        @foreach ($product->variables ?? [] as $field)
                            @php
                                $label = app(ProductOptions::class)->label($field);
                            @endphp
                            <div class="mb-3">
                                <label class="form-label"
                                    for="product-variable-{{ $field['key'] }}">{{ $label }}</label>
                                @if ($field['type'] === 'choice')
                                    <select class="form-select" id="product-variable-{{ $field['key'] }}"
                                        name="variables[{{ $field['key'] }}]" @required($field['required'] ?? true)>
                                        <option value="">{{ __('shop::messages.products.options.choose') }}</option>
                                        @foreach ($field['choices'] as $choice)
                                            <option @selected(old('variables.' . $field['key'], $options['variables'][$field['key']] ?? '') === $choice)>{{ $choice }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <input class="form-control" id="product-variable-{{ $field['key'] }}"
                                        name="variables[{{ $field['key'] }}]"
                                        type="{{ $field['type'] === 'integer' ? 'number' : 'text' }}"
                                        @if ($field['type'] === 'integer') min="{{ $field['min'] ?? -1000000000 }}"
                                        max="{{ $field['max'] ?? 1000000000 }}" @else
                                        maxlength="{{ $field['max'] ?? 120 }}" minlength="{{ $field['min'] ?? 1 }}" @endif
                                        value="{{ old('variables.' . $field['key'], $options['variables'][$field['key']] ?? '') }}"
                                        @required($field['required'] ?? true)>
                                    @if ($field['type'] === 'text')
                                        <p class="form-text">{{ __('shop::messages.products.options.text_help') }}</p>
                                    @endif
                                @endif
                            </div>
                        @endforeach
                        @if ($serverChoices->isNotEmpty())
                            <div class="mb-3">
                                <label class="form-label"
                                    for="product-server">{{ __('shop::messages.products.options.delivery_server') }}</label>
                                <select class="form-select" id="product-server" name="server_id" required>
                                    <option value="">{{ __('shop::messages.products.options.choose') }}</option>
                                    @foreach ($serverChoices as $server)
                                        <option value="{{ $server->id }}" @selected(old('server_id', $options['server_id'] ?? null) == $server->id)>
                                            {{ $server->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                        <div class="border-top pt-3 mt-3" data-shop-purchase
                            data-availability-url="{{ route('plugins.shop.public.products.availability', $product->id) }}"
                            data-availability-unavailable="{{ __('shop::messages.products.options.unavailable') }}"
                            data-trial-unavailable="{{ __('shop::subscriptions.trial_ineligible') }}"
                            data-purchase-unavailable="{{ $quantityLimit === 0 ? $unavailableReason : '' }}"
                            data-quantity-error="{{ __('shop::messages.products.options.quantity_range', ['max' => $quantityLimit]) }}">
                            @php
                                $canGift = auth()->check() && ($product->kind ?? 'product') !== 'giftcard';
                                $recipient = old('recipient', $options['recipient'] ?? '') ?? '';
                                $giftRequested = $recipient !== '' || $errors->has('recipient');
                            @endphp
                            <div class="row g-4 align-items-center">
                                <div class="col-md">
                                    @if ($primaryTrial)
                                        <p class="fs-3 fw-bold mb-1">
                                            {{ __('shop::subscriptions.trial_duration', ['days' => $product->trial_days]) }}
                                        </p>
                                        <p class="small text-body-secondary mb-0">
                                            {{ __('shop::subscriptions.trial_next', ['amount' => Money::format($product->price_minor), 'currency' => $product->currency, 'interval' => __('shop::subscriptions.periods.' . $product->billing_interval)]) }}
                                        </p>
                                        @if (!$hasTrial)
                                            <p class="small text-body-secondary mt-2 mb-0">
                                                {{ __('shop::subscriptions.trial_ineligible') }}</p>
                                        @endif
                                    @else
                                        <p class="fs-3 fw-bold mb-0">{{ Money::format($product->price_minor) }}
                                            {{ $product->currency }}@if ($product->billing_interval)
                                                <span
                                                    class="fs-6 fw-normal text-body-secondary">/{{ __('shop::subscriptions.periods.' . $product->billing_interval) }}</span>
                                            @endif
                                        </p>
                                    @endif
                                </div>
                                <div class="col-md-auto" @if ($canGift) data-shop-gifting @endif>
                                    @if ($canGift)
                                        <input type="hidden" name="recipient" value="">
                                        <div class="mb-3" id="product-gift-fields" data-shop-gift-fields
                                            @if (!$giftRequested) hidden @endif>
                                            <label class="form-label"
                                                for="product-recipient">{{ __('shop::gifting.recipient') }}</label>
                                            <input class="form-control" id="product-recipient" name="recipient"
                                                value="{{ $recipient }}" autocomplete="off" maxlength="64"
                                                aria-describedby="product-recipient-help" @disabled(!$giftRequested)
                                                @required($giftRequested)>
                                            <p class="form-text mb-0" id="product-recipient-help">
                                                {{ __('shop::gifting.recipient_help') }}</p>
                                            @if ($product->billing_interval)
                                                <p class="form-text mb-0">{{ __('shop::gifting.recurring_help') }}</p>
                                            @endif
                                        </div>
                                    @endif
                                    <div class="d-flex align-items-end justify-content-end gap-2">
                                        @if ($quantityLimit <= 1)
                                            <input type="hidden" name="quantity" value="1">
                                        @else
                                            <div style="width: 5rem">
                                                <label class="form-label small mb-1"
                                                    for="product-quantity">{{ __('shop::messages.products.options.quantity') }}</label>
                                                <input class="form-control" id="product-quantity" type="number"
                                                    name="quantity" min="1" max="{{ $quantityLimit }}"
                                                    value="{{ old('quantity', $quantity ?? 1) }}" required>
                                            </div>
                                        @endif
                                        <span class="d-block flex-grow-1 flex-md-grow-0" role="group"
                                            data-shop-submit-wrapper
                                            @if ($quantityLimit === 0) tabindex="0" title="{{ $unavailableReason }}" aria-label="{{ $unavailableReason }}" @endif>
                                            <button class="btn btn-primary w-100" type="submit" name="trial_requested"
                                                value="{{ $primaryTrial ? '1' : '0' }}" data-shop-submit
                                                @disabled($quantityLimit === 0)>{{ $purchaseLabel }}</button>
                                        </span>
                                    </div>
                                    <div
                                        class="d-flex flex-wrap align-items-center justify-content-end gap-2 column-gap-3 mt-3">
                                        @if ($secondaryTrial !== null)
                                            <span class="d-inline-block" role="group" data-shop-submit-wrapper
                                                @if ($quantityLimit === 0) tabindex="0" title="{{ $unavailableReason }}" aria-label="{{ $unavailableReason }}" @endif>
                                                <button class="btn btn-link btn-sm text-body-secondary p-0" type="submit"
                                                    name="trial_requested" value="{{ $secondaryTrial ? '1' : '0' }}"
                                                    data-shop-submit
                                                    @disabled($quantityLimit === 0)>{{ $secondaryTrial ? __('shop::subscriptions.try_free') : __('shop::subscriptions.without_trial') }}</button>
                                            </span>
                                        @endif
                                        @if ($canGift)
                                            <button
                                                class="btn btn-link btn-sm text-body-secondary p-0 text-decoration-none"
                                                type="button" data-shop-gift-toggle
                                                aria-expanded="{{ $giftRequested ? 'true' : 'false' }}"
                                                aria-controls="product-gift-fields"><i class="bi bi-gift me-1"
                                                    aria-hidden="true"></i>{{ __('shop::gifting.offer') }}</button>
                                        @endif
                                    </div>
                                    @if ($quantityLimit === 0)
                                        <p class="small mt-3 mb-0"><a
                                                href="{{ route('plugins.shop.public.cart.index') }}">{{ __('shop::messages.site.catalog.view_cart') }}</a>
                                        </p>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </article>
@endsection
@push('scripts')
    <script type="module"
        src="{{ route('site.extensions.assets.show', ['id' => 'shop', 'asset' => 'payments.js']) }}">
    </script>
@endpush
