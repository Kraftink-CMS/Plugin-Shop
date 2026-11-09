@use('App\Kraft\Wallet\Money')
@extends('default.layouts.layout')
@section('content')
    <section>
        <header class="page-heading d-flex flex-wrap justify-content-between align-items-center gap-3">
            <h1 class="h2 mb-0">{{ $selectedCategory?->name ?? __('shop::messages.title') }}</h1>
            <nav class="d-flex flex-wrap gap-2" aria-label="{{ __('shop::messages.title') }}">
                @auth
                    <a class="btn btn-outline-secondary"
                        href="{{ route('plugins.shop.public.orders.index') }}">{{ __('shop::messages.site.orders.my_orders') }}</a>
                @else
                    @if ($guestAccess ?? false)
                        <a class="btn btn-outline-secondary"
                            href="{{ route('plugins.shop.public.guests.index') }}">{{ __('shop::messages.site.orders.my_orders') }}</a>
                    @endif
                @endauth
                <a class="btn btn-primary" href="{{ route('plugins.shop.public.cart.index') }}"><i class="bi bi-bag me-2"
                        aria-hidden="true"></i>{{ __('shop::messages.site.cart.cart') }}</a>
            </nav>
        </header>
        <form method="get" action="{{ route('plugins.shop.public.catalog.index') }}" class="site-panel p-3 p-lg-4 mb-4"
            role="search">
            <div class="row g-3 align-items-end">
                <div class="col-md-6">
                    <label class="form-label" for="shop-search">{{ __('shop::admin.catalog.search') }}</label>
                    <input class="form-control" id="shop-search" type="search" name="q" value="{{ request('q') }}"
                        maxlength="100">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="shop-category">{{ __('shop::admin.catalog.category') }}</label>
                    <select class="form-select" name="category" id="shop-category">
                        <option value="">{{ __('shop::admin.catalog.all_categories') }}</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected($selectedCategory?->id === $category->id)>
                                {{ str_repeat('— ', $category->depth) }}{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary w-100">{{ __('shop::admin.catalog.filter') }}</button>
                </div>
            </div>
        </form>
        @if ($selectedCategory)
            <section class="card card-body p-4 mb-4">
                @if (isset($images[$selectedCategory->image_id]))
                    <img class="img-fluid rounded mb-3" src="{{ route('site.media.show', $selectedCategory->image_id) }}"
                        alt="{{ $images[$selectedCategory->image_id]->displayText('alt') ?: $selectedCategory->name }}"
                        loading="lazy" width="{{ $images[$selectedCategory->image_id]->width }}"
                        height="{{ $images[$selectedCategory->image_id]->height }}">
                @endif
                <h2 class="h4">{{ $selectedCategory->name }}</h2>
                <div class="prose">
                    {!! $selectedCategory->description !!}
                </div>
            </section>
        @endif
        @if ($publicSales['goal'] || $publicSales['recent']->isNotEmpty())
            <div class="row g-4 mb-4">
                @if ($publicSales['goal'])
                    <section class="col-lg-6">
                        <div class="card h-100">
                            <div class="card-body p-4">
                                <h2 class="h5">{{ $publicSales['goal']['title'] }}</h2>
                                <p>{{ $publicSales['goal']['amount'] }} / {{ $publicSales['goal']['target'] }}
                                    {{ $publicSales['goal']['currency'] }}</p>
                                <div class="progress" role="progressbar" aria-label="{{ $publicSales['goal']['title'] }}"
                                    aria-valuenow="{{ $publicSales['goal']['percent'] }}" aria-valuemin="0"
                                    aria-valuemax="100">
                                    <div class="progress-bar" style="width: {{ $publicSales['goal']['percent'] }}%">
                                        {{ $publicSales['goal']['percent'] }}%</div>
                                </div>
                            </div>
                        </div>
                    </section>
                @endif
                @if ($publicSales['recent']->isNotEmpty())
                    <section class="col-lg-6">
                        <div class="card h-100">
                            <div class="card-body p-4">
                                <h2 class="h5">{{ __('shop::admin.storefront.recent') }}</h2>
                                <ul class="list-unstyled mb-0">
                                    @foreach ($publicSales['recent'] as $purchase)
                                        <li class="py-2 border-bottom">
                                            <a href="{{ $purchase['url'] }}">{{ $purchase['name'] }}</a>
                                            <span>× {{ $purchase['quantity'] }}</span>
                                            @if ($publicSales['show_amounts'])
                                                <span class="ms-2">{{ $purchase['amount'] }}
                                                    {{ $purchase['currency'] }}</span>
                                            @endif
                                            <small class="d-block text-body-secondary">{{ $purchase['date'] }}</small>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    </section>
                @endif
            </div>
        @endif
        <div id="shop-catalogue-products" data-ajax-pagination tabindex="-1" class="vstack gap-3">
            <div class="row g-4">
                @forelse($products as $product)
                    @php
                        $addQuantityLimit = max(
                            0,
                            $product->quantityLimit() - (int) session('shop.cart.' . $product->id, 0),
                        );
                        $unavailableReason = __(
                            $product->quantityLimit() === 0
                                ? 'shop::messages.products.options.unavailable'
                                : 'shop::messages.products.options.cart_limit',
                        );
                    @endphp
                    <div class="col-md-6 col-xl-4">
                        <article class="card product-card h-100 overflow-hidden">
                            @if (isset($images[$product->image_id]))
                                <a class="product-image d-block ratio ratio-16x9 bg-body-tertiary"
                                    href="{{ route('plugins.shop.public.products.show', [$product->id, $product->slug]) }}"
                                    tabindex="-1" aria-hidden="true">
                                    <img class="card-img-top object-fit-contain p-4"
                                        src="{{ route('site.media.show', $product->image_id) }}"
                                        alt="{{ $images[$product->image_id]->displayText('alt') ?: $product->name }}"
                                        loading="lazy" width="{{ $images[$product->image_id]->width }}"
                                        height="{{ $images[$product->image_id]->height }}">
                                </a>
                            @endif
                            <div class="card-body p-4 d-flex flex-column">
                                <p class="small text-body-secondary mb-2">{{ $product->category }}</p>
                                <h2 class="h5 mb-3"><a class="text-decoration-none"
                                        href="{{ route('plugins.shop.public.products.show', [$product->id, $product->slug]) }}">{{ $product->name }}</a>
                                </h2>
                                <div class="prose text-body-secondary mb-3">
                                    {!! $product->description !!}
                                </div>
                                @if ($product->billing_interval && $product->trial_eligible)
                                    <p class="fs-4 fw-bold mb-1">
                                        {{ __('shop::subscriptions.trial_duration', ['days' => $product->trial_days]) }}
                                    </p>
                                    <p class="text-body-secondary mb-3">
                                        {{ __('shop::subscriptions.trial_next', ['amount' => Money::format($product->price_minor), 'currency' => $product->currency, 'interval' => __('shop::subscriptions.periods.' . $product->billing_interval)]) }}
                                    </p>
                                @else
                                    <p class="fs-4 fw-bold mb-3">
                                        @if ($product->custom_price)
                                            {{ __('shop::messages.products.options.from') }}
                                        @endif
                                        {{ Money::format($product->custom_price ? $product->minimum_price_minor ?? $product->price_minor : $product->price_minor) }}
                                        {{ $product->currency }}@if ($product->billing_interval)
                                            /{{ __('shop::subscriptions.periods.' . $product->billing_interval) }}
                                        @endif
                                    </p>
                                @endif
                                @if ($product->wallet_credit_minor)
                                    <p class="small">
                                        {{ __('shop::commerce.wallet.credit_summary', ['amount' => Money::format($product->wallet_credit_minor), 'currency' => $product->wallet_currency]) }}
                                    </p>
                                @endif
                                @if ($product->stock !== null)
                                    <p class="small text-body-secondary">{{ __('shop::messages.site.catalog.stock') }}
                                        {{ $product->stock }}</p>
                                @endif
                                @if ($product->billing_interval)
                                    <a class="btn btn-primary mt-auto"
                                        href="{{ route('plugins.shop.public.products.show', [$product->id, $product->slug]) }}">{{ $product->trial_eligible ? __('shop::subscriptions.try_free') : __('shop::subscriptions.subscribe') }}</a>
                                @elseif (
                                    ($product->kind ?? 'product') === 'giftcard' ||
                                        $product->custom_price ||
                                        !empty($product->variables ?? []) ||
                                        ($product->has_server_choice ?? false))
                                    <a class="btn btn-primary mt-auto"
                                        href="{{ route('plugins.shop.public.products.show', [$product->id, $product->slug]) }}">{{ __('shop::messages.products.options.configure') }}</a>
                                @else
                                    <form data-ajax="true" data-redirect-url="response" class="mt-auto" method="post"
                                        action="{{ route('plugins.shop.public.cart.update', $product->id) }}">
                                        @csrf
                                        <input type="hidden" name="intent" value="add">
                                        <input type="hidden" name="quantity" value="1">
                                        <span class="d-inline-block" role="group"
                                            @if ($addQuantityLimit === 0) tabindex="0" title="{{ $unavailableReason }}" aria-label="{{ $unavailableReason }}" @endif>
                                            <button class="btn btn-primary"
                                                @disabled($addQuantityLimit === 0)>{{ __('shop::messages.site.catalog.add_to_cart') }}</button>
                                        </span>
                                        @if ($addQuantityLimit === 0)
                                            <p class="small mt-2 mb-0">
                                                <a
                                                    href="{{ route('plugins.shop.public.cart.index') }}">{{ __('shop::messages.site.catalog.view_cart') }}</a>
                                            </p>
                                        @endif
                                    </form>
                                @endif
                            </div>
                        </article>
                    </div>
                    @empty
                        <div class="col-12">
                            <div class="card card-body empty-state text-center py-5">
                                <i class="bi bi-bag fs-2 mb-3" aria-hidden="true"></i>
                                <p class="mb-0">{{ __('shop::messages.site.catalog.empty') }}</p>
                            </div>
                        </div>
                    @endforelse
                </div>
                <div class="mt-4">
                    {{ $products->links() }}
                </div>
            </div>
        </section>
    @endsection
