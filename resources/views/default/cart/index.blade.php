@use('KraftInk\Plugins\Shop\Services\Catalog\ProductOptions')
@use('App\Kraft\Wallet\Money')
@extends('default.layouts.layout')
@section('title', __('shop::messages.site.cart.cart'))
@section('content')
    @php
        $trialRequested = false;
    @endphp
    <section>
        <header class="page-heading d-flex flex-wrap align-items-center justify-content-between gap-3">
            <h1 class="h2 mb-0">{{ __('shop::messages.site.cart.cart') }}</h1>
            @if ($capabilities->extension('shop'))
                <a class="btn btn-outline-secondary" href="{{ route('plugins.shop.public.catalog.index') }}"><i
                        class="bi bi-arrow-left me-2"
                        aria-hidden="true"></i>{{ __('shop::messages.site.catalog.continue') }}</a>
            @endif
        </header>
        <div class="row g-4 align-items-start">
            <div class="{{ $products->isNotEmpty() ? 'col-lg-7' : 'col-12' }}">
                @forelse($products as $product)
                    @php
                        $quantityLimit = $product->quantityLimit();
                    @endphp
                    <article class="card card-body p-4 mb-4">
                        <header class="d-flex justify-content-between align-items-start gap-3 mb-3">
                            <h2 class="h5 mb-0">{{ $product->name }}</h2>
                            <form data-ajax="true" data-redirect-url="response" method="post"
                                action="{{ route('plugins.shop.public.cart.update', $product->id) }}">
                                @csrf
                                <input type="hidden" name="intent" value="set">
                                <input type="hidden" name="quantity" value="0">
                                <button class="btn btn-outline-secondary btn-sm" type="submit"
                                    aria-label="{{ __('shop::messages.actions.delete') }} {{ $product->name }}"
                                    title="{{ __('shop::messages.actions.delete') }}"><i class="bi bi-trash"
                                        aria-hidden="true"></i></button>
                            </form>
                        </header>
                        @if (($product->kind ?? 'product') !== 'giftcard' && auth()->check() && !empty($options[$product->id]['recipient']))
                            <p class="small mb-2">
                                {{ __('shop::gifting.for_member', ['name' => $options[$product->id]['recipient']]) }}
                            </p>
                        @endif
                        @if (($product->kind ?? 'product') === 'giftcard')
                            <p class="small mb-2">
                                {{ !empty($options[$product->id]['giftcard_recipient']) ? __('shop::commerce.giftcards.recipient') . ' : ' . $options[$product->id]['giftcard_recipient'] : __('shop::commerce.giftcards.recipient_help') }}
                            </p>
                        @endif
                        @if (!empty($options[$product->id]['variables']))
                            <dl class="small mb-2">
                                @foreach ($product->variables ?? [] as $field)
                                    <dt>{{ app(ProductOptions::class)->label($field) }}</dt>
                                    <dd>{{ $options[$product->id]['variables'][$field['key']] ?? '' }}</dd>
                                @endforeach
                            </dl>
                        @endif
                        @if (isset($options[$product->id]['server_id']) && isset($serverNames[$options[$product->id]['server_id']]))
                            <p class="small">{{ __('shop::messages.products.options.delivery_server') }} :
                                {{ $serverNames[$options[$product->id]['server_id']] }}
                            </p>
                        @endif
                        @if (auth()->check() ||
                                ($product->kind ?? 'product') === 'giftcard' ||
                                $product->custom_price ||
                                !empty($product->variables ?? []) ||
                                ($product->has_server_choice ?? false))
                            <a class="small"
                                href="{{ route('plugins.shop.public.products.show', ['id' => $product->id, 'slug' => $product->slug, 'edit' => 1]) }}">{{ __('common.actions.edit') }}</a>
                        @endif
                        <p class="fw-semibold fs-5 mb-4">
                            {{ $cart[$product->id] }} × {{ Money::format($product->cart_price_minor) }} =
                            {{ Money::format($product->cart_price_minor * $cart[$product->id]) }} {{ $product->currency }}
                        </p>
                        @if ($quantityLimit > 1)
                            <form class="row g-3 align-items-end" data-ajax="true" data-redirect-url="response"
                                method="post" action="{{ route('plugins.shop.public.cart.update', $product->id) }}">
                                @csrf
                                <input type="hidden" name="intent" value="set">
                                <div class="col-sm-5 col-lg-3">
                                    <label class="form-label d-block mb-2"
                                        for="qty-{{ $product->id }}">{{ __('shop::messages.site.cart.quantity') }}</label>
                                    <input class="form-control" id="qty-{{ $product->id }}" type="number" min="1"
                                        max="{{ $quantityLimit }}" name="quantity" value="{{ $cart[$product->id] }}"
                                        required>
                                </div>
                                <div class="col-sm-auto">
                                    <button class="btn btn-primary">{{ __('shop::messages.actions.update') }}</button>
                                </div>
                            </form>
                        @endif
                    </article>
                @empty
                    <div class="card card-body empty-state text-center py-5">
                        <i class="bi bi-bag fs-2 mb-3" aria-hidden="true"></i>
                        <p class="mb-0">{{ __('shop::messages.site.cart.cart_empty') }}</p>
                    </div>
                @endforelse
            </div>
            @if ($products->isNotEmpty())
                <div class="col-lg-5">
                    @if (!empty($cartError))
                        <p class="alert alert-warning" role="alert">{{ $cartError }}</p>
                    @endif
                    @auth
                        @include('shop::default.commerce.partials.checkout', [
                            'checkoutAction' => route('plugins.shop.public.checkout.store'),
                            'checkoutTitle' => __('shop::messages.site.cart.checkout'),
                        ])
                    @else
                        <a class="btn btn-primary"
                            href="{{ route('login') }}">{{ __('shop::messages.site.catalog.login') }}</a>
                        @if ($guestEnabled ?? false)
                            <div class="card mt-3">
                                <div class="card-body p-4">
                                    <h2 class="h4 mb-3">{{ __('shop::messages.guests.title') }}</h2>
                                    @if (!$guestBuyer)
                                        <p>{{ __('shop::messages.guests.help') }}</p>
                                        <a class="btn btn-outline-primary"
                                            href="{{ route('plugins.shop.public.guests.index') }}">{{ __('shop::messages.guests.continue') }}</a>
                                    @elseif (!$guestEligible)
                                        <p class="mb-0">{{ __('shop::messages.guests.member_required') }}</p>
                                    @elseif ($guestGateways->isEmpty())
                                        <p class="mb-0">{{ __('shop::messages.errors.gateway_unavailable') }}</p>
                                    @else
                                        <p>{{ __('shop::messages.guests.verified', ['email' => $guestBuyer->email_address]) }}
                                        </p>
                                        <form data-ajax="false" method="post"
                                            action="{{ route('plugins.shop.public.guests.checkout.store') }}">
                                            @csrf
                                            <input type="hidden" name="checkout_key" value="{{ $checkoutKey }}">
                                            @include('shop::default.commerce.partials.billing_fields')
                                            <div class="mb-3">
                                                <label class="form-label"
                                                    for="guest-provider">{{ __('shop::messages.site.cart.payment_method') }}</label>
                                                <select class="form-select" id="guest-provider" name="provider" required>
                                                    @foreach ($guestGateways as $gateway)
                                                        <option value="{{ $gateway->id() }}" @selected(old('provider') === $gateway->id())>
                                                            {{ $gateway->name() }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            @if ($acceptsCoupon)
                                                <div class="mb-3">
                                                    <label class="form-label"
                                                        for="guest-coupon">{{ __('shop::messages.site.cart.coupon') }}</label>
                                                    <input class="form-control" id="guest-coupon" name="coupon"
                                                        value="{{ old('coupon') }}" maxlength="60">
                                                </div>
                                            @endif
                                            @foreach ($products as $product)
                                                @if (json_decode($product->commands ?: '[]', true))
                                                    <fieldset class="mb-3">
                                                        <legend class="h5">{{ $product->name }}</legend>
                                                        <p>{{ __('shop::messages.guests.target_help') }}</p>
                                                        <label class="form-label"
                                                            for="guest-player-{{ $product->id }}">{{ __('shop::messages.guests.player_name') }}</label>
                                                        <input class="form-control mb-2" id="guest-player-{{ $product->id }}"
                                                            name="targets[{{ $product->id }}][player_name]"
                                                            value="{{ old('targets.' . $product->id . '.player_name') }}"
                                                            maxlength="60" @required(!$guestIdentityUsesId)
                                                            @if (!$guestIdentityUsesId) pattern="[a-zA-Z0-9_]{1,32}" @endif>
                                                        <label class="form-label"
                                                            for="guest-uuid-{{ $product->id }}">{{ __('shop::messages.guests.player_uuid') }}</label>
                                                        <input class="form-control" id="guest-uuid-{{ $product->id }}"
                                                            name="targets[{{ $product->id }}][player_uuid]"
                                                            value="{{ old('targets.' . $product->id . '.player_uuid') }}"
                                                            maxlength="64" @required($guestIdentityUsesId || $product->requires_online || str_contains($product->commands, '{uuid}'))>
                                                    </fieldset>
                                                @endif
                                            @endforeach
                                            @include('shop::default.commerce.partials.terms')
                                            @include('shop::default.cart.summary')
                                            <button
                                                class="btn btn-primary w-100">{{ __('shop::messages.site.cart.pay') }}</button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        @endif
                    @endauth
                </div>
            @endif
        </div>
    </section>
    @include('shop::default.commerce.partials.tax_note')
@endsection
@if ($products->isNotEmpty())
    @push('scripts')
        <script type="module"
            src="{{ route('site.extensions.assets.show', ['id' => 'shop', 'asset' => 'payments.js']) }}">
        </script>
    @endpush
@endif
