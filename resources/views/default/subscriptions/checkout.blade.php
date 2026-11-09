@use('App\Kraft\Wallet\Money')
@use('KraftInk\Plugins\Shop\Services\Catalog\ProductOptions')
@extends('default.layouts.layout')
@section('title', __('shop::subscriptions.subscribe'))
@section('content')
    @php
        $trialRequested = !empty($options[$product->id]['trial_requested']);
    @endphp
    <section>
        <header class="page-heading d-flex flex-wrap align-items-center justify-content-between gap-3">
            <h1 class="h2 mb-0">{{ __('shop::subscriptions.subscribe') }}</h1>
            <a class="btn btn-outline-secondary" href="{{ route('plugins.shop.public.catalog.index') }}"><i
                    class="bi bi-arrow-left me-2" aria-hidden="true"></i>{{ __('shop::messages.site.catalog.continue') }}</a>
        </header>
        <div class="row g-4 align-items-start">
            <div class="col-lg-6">
                <article class="card card-body p-4">
                    <h2 class="h4 mb-3">{{ $product->name }}</h2>
                    <div class="prose text-body-secondary mb-3">{!! $product->description !!}</div>
                    @if (!empty($options[$product->id]['recipient']))
                        <p class="mb-2">
                            {{ __('shop::gifting.for_member', ['name' => $options[$product->id]['recipient']]) }}</p>
                        <p class="small text-body-secondary mb-3">{{ __('shop::gifting.recurring_help') }}</p>
                    @endif
                    @if (!empty($options[$product->id]['variables']))
                        <dl class="small mb-3">
                            @foreach ($product->variables ?? [] as $field)
                                <dt>{{ app(ProductOptions::class)->label($field) }}</dt>
                                <dd>{{ $options[$product->id]['variables'][$field['key']] ?? '' }}</dd>
                            @endforeach
                        </dl>
                    @endif
                    <p class="fs-5 fw-semibold mb-3">
                        @if ($trialRequested)
                            {{ __('shop::subscriptions.trial_price', ['today' => Money::format(0), 'amount' => Money::format($product->cart_price_minor), 'currency' => $product->currency, 'days' => $product->trial_days, 'interval' => __('shop::subscriptions.periods.' . $product->billing_interval)]) }}
                        @else
                            {{ Money::format($product->cart_price_minor) }}
                            {{ $product->currency }}/{{ __('shop::subscriptions.periods.' . $product->billing_interval) }}
                        @endif
                    </p>
                    @if (!empty($productErrors[$product->id]))
                        <p class="alert alert-warning" role="alert">{{ $productErrors[$product->id] }}</p>
                    @endif
                    <a class="small"
                        href="{{ route('plugins.shop.public.products.show', ['id' => $product->id, 'slug' => $product->slug, 'edit' => 1]) }}">{{ __('common.actions.edit') }}</a>
                </article>
            </div>
            <div class="col-lg-6">
                @include('shop::default.commerce.partials.checkout', [
                    'checkoutAction' => route('plugins.shop.public.subscriptions.store'),
                    'checkoutTitle' => __('shop::messages.site.cart.checkout'),
                    'checkoutLabel' => $trialRequested
                        ? __('shop::subscriptions.try_free')
                        : __('shop::messages.site.cart.pay'),
                ])
            </div>
        </div>
    </section>
    @include('shop::default.commerce.partials.tax_note')
@endsection
@push('scripts')
    <script type="module"
        src="{{ route('site.extensions.assets.show', ['id' => 'shop', 'asset' => 'payments.js']) }}">
    </script>
@endpush
