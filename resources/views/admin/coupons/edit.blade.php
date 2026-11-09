@use('Illuminate\Support\Collection')
@use('App\Models\Accounts\Role')
@use('KraftInk\Plugins\Shop\Models\Catalog\Category')
@use('KraftInk\Plugins\Shop\Models\Catalog\Product')
@use('App\Kraft\Currencies\CurrencyRegistry')
@use('App\Kraft\Wallet\Money')
@php
    /** @var Collection<int, Role> $roles */
    /** @var Collection<int, Category> $categories */
    /** @var Collection<int, Product> $products */
@endphp
@extends('admin.layouts.layout')
@section('title', $coupon ? __('shop::admin.discounts.coupons.edit_coupon') . ' · ' . $coupon->code : __('shop::admin.navigation.add.coupons'))
@section('actions')
    <a class="btn btn-outline-secondary" href="{{ route('plugins.shop.admin.coupons.index') }}">
        {{ __('shop::admin.discounts.coupons.coupons') }}</a>
@endsection
@section('content')
    @php
        $value = fn($key, $fallback = null) => old($key, $coupon?->{$key} ?? $fallback);
    @endphp
    <form data-ajax="true" data-redirect-url="{{ $coupon ? '' : route('plugins.shop.admin.coupons.index') }}" method="post" action="{{ $coupon ? route('plugins.shop.admin.coupons.update', $coupon->id) : route('plugins.shop.admin.coupons.store') }}"
        data-shop-coupon data-shop-errors='@json($errors->keys())'>
        @csrf
        @if ($coupon)
            @method('PUT')
        @endif
        <section class="card">
            <div class="card-header p-0">
                <ul class="nav nav-tabs card-header-tabs m-0" role="tablist">
                    @foreach (['discount', 'eligibility', 'limits'] as $panel)
                        <li class="nav-item" role="presentation">
                            <button @class(['nav-link', 'active' => $loop->first]) id="coupon-tab-{{ $panel }}" type="button"
                                data-bs-toggle="tab" data-bs-target="#coupon-{{ $panel }}" role="tab"
                                aria-controls="coupon-{{ $panel }}"
                                aria-selected="{{ $loop->first ? 'true' : 'false' }}">{{ __('shop::admin.products.tabs.' . $panel) }}</button>
                        </li>
                    @endforeach
                </ul>
            </div>
            <div class="card-body tab-content">
                <div class="tab-pane fade show active" id="coupon-discount" role="tabpanel"
                    aria-labelledby="coupon-tab-discount">
                    <div class="row g-3 mb-3">
                        <div class="col-md-8">
                            <label class="form-label" for="coupon_code">{{ __('shop::messages.labels.code') }}</label>
                            <input class="form-control font-monospace" id="coupon_code" name="code"
                                value="{{ $value('code') }}" minlength="3" maxlength="60" pattern="[A-Za-z0-9_-]{3,60}"
                                required>
                            <div class="form-text">
                                {{ __('shop::admin.discounts.coupons.coupon_code_help') }}
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="coupon_type">{{ __('shop::admin.discounts.coupons.type') }}</label>
                            <select class="form-select" id="coupon_type" name="type" data-coupon-type>
                                @foreach (['percent', 'fixed'] as $type)
                                    <option value="{{ $type }}" @selected($value('type', 'percent') === $type)>
                                        {{ $type === 'fixed' ? __('shop::admin.discounts.coupons.fixed_discount') : __('shop::admin.discounts.coupons.percent') }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6" data-coupon-percent>
                            <label class="form-label" for="coupon_percent">{{ __('shop::admin.discounts.coupons.discount') }}</label>
                            <div class="input-group">
                                <input class="form-control" id="coupon_percent" name="percent_amount" inputmode="decimal"
                                    pattern="[0-9]+([.,][0-9]{1,2})?"
                                    value="{{ old('percent_amount', ($coupon?->type ?? 'percent') === 'percent' && $coupon ? Money::decimal((int) $coupon->amount) : '') }}"
                                    required>
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <div class="col-md-6" data-coupon-fixed>
                            <label class="form-label" for="coupon_amount">{{ __('shop::admin.discounts.coupons.discount') }}</label>
                            <input class="form-control" id="coupon_amount" name="discount_amount" inputmode="decimal"
                                pattern="[0-9]+([.,][0-9]{1,2})?"
                                value="{{ old('discount_amount', $coupon && $coupon->type === 'fixed' ? Money::decimal((int) $coupon->amount) : '') }}"
                                required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="coupon_currency">{{ __('shop::messages.labels.currency') }}</label>
                            <select class="form-select" id="coupon_currency" name="currency" data-searchable-select required>
                                @if (!app(CurrencyRegistry::class)->find((string) $value('currency', 'EUR')))
                                    <option value="{{ $value('currency', 'EUR') }}" selected disabled>{{ $value('currency', 'EUR') }}</option>
                                @endif
                                @foreach (app(CurrencyRegistry::class)->all() as $currency)
                                    <option value="{{ $currency->code }}" @selected($value('currency', 'EUR') === $currency->code)>
                                        {{ $currency->code }}{{ $currency->label() === $currency->code ? '' : ' · ' . $currency->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="minimum_amount">{{ __('shop::admin.discounts.coupons.minimum_cart') }}</label>
                        <input class="form-control" id="minimum_amount" name="minimum_amount" inputmode="decimal"
                            pattern="[0-9]+([.,][0-9]{1,2})?"
                            value="{{ old('minimum_amount', Money::decimal((int) ($coupon->minimum_minor ?? 0))) }}"
                            required>
                    </div>
                    <input type="hidden" name="enabled" value="0">
                    <div class="form-check">
                        <input class="form-check-input" id="coupon_enabled" name="enabled" type="checkbox" value="1"
                            @checked($value('enabled', true))>
                        <label class="form-check-label" for="coupon_enabled">{{ __('shop::messages.states.enabled') }}</label>
                    </div>
                </div>
                <div class="tab-pane fade" id="coupon-eligibility" role="tabpanel" aria-labelledby="coupon-tab-eligibility">
                    <p class="text-body-secondary">{{ __('shop::admin.discounts.coupons.coupon_scope_help') }}</p>
                    <div class="mb-4">
                        <label class="form-label" for="coupon_role">{{ __('shop::admin.products.fields.role') }}</label>
                        <select class="form-select" id="coupon_role" name="role_id">
                            <option value="">{{ __('shop::admin.discounts.coupons.all_members') }}</option>
                            @foreach ($roles as $role)
                                <option value="{{ $role->id }}" @selected((int) $value('role_id') === $role->id)>{{ $role->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="row g-4">
                        <div class="col-lg-6">
                            <fieldset>
                                <legend class="fs-6">{{ __('shop::admin.catalog.categories') }}</legend>
                                @forelse($categories as $category)
                                    <div class="form-check">
                                        <input class="form-check-input" id="coupon_category_{{ $category->id }}"
                                            type="checkbox" name="category_ids[]" value="{{ $category->id }}"
                                            @checked(in_array(
                                                    $category->id,
                                                    array_map('intval', old('category_ids', ($coupon?->category_ids ?? []))),
                                                    true))>
                                        <label class="form-check-label"
                                            for="coupon_category_{{ $category->id }}">{{ $category->name }}</label>
                                    </div>
                                @empty
                                    <p class="text-body-secondary">{{ __('shop::admin.catalog.empty.no_categories') }}</p>
                                @endforelse
                            </fieldset>
                        </div>
                        <div class="col-lg-6">
                            <fieldset>
                                <legend class="fs-6">{{ __('shop::admin.products.fields.products') }}</legend>
                                @forelse($products as $product)
                                    <div class="form-check">
                                        <input class="form-check-input" id="coupon_product_{{ $product->id }}"
                                            type="checkbox" name="product_ids[]" value="{{ $product->id }}"
                                            @checked(in_array(
                                                    $product->id,
                                                    array_map('intval', old('product_ids', ($coupon?->product_ids ?? []))),
                                                    true))>
                                        <label class="form-check-label"
                                            for="coupon_product_{{ $product->id }}">{{ $product->name }} ·
                                            {{ strtoupper($product->locale) }}</label>
                                    </div>
                                @empty
                                    <p class="text-body-secondary">{{ __('shop::admin.catalog.empty.no_products') }}</p>
                                @endforelse
                            </fieldset>
                        </div>
                    </div>
                </div>
                <div class="tab-pane fade" id="coupon-limits" role="tabpanel" aria-labelledby="coupon-tab-limits">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label" for="coupon_starts">{{ __('shop::admin.discounts.coupons.starts_at') }}</label>
                            <x-forms.local-datetime name="starts_at" :value="$coupon?->starts_at" id="coupon_starts" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="coupon_expires">{{ __('shop::admin.discounts.coupons.expires_at') }}</label>
                            <x-forms.local-datetime name="expires_at" :value="$coupon?->expires_at" id="coupon_expires" />
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="coupon_max_uses">{{ __('shop::admin.discounts.coupons.max_uses') }}</label>
                            <input class="form-control" id="coupon_max_uses" name="max_uses" type="number"
                                min="1" max="1000000" value="{{ $value('max_uses') }}">
                            <div class="form-text">
                                {{ __('shop::admin.catalog.unlimited_help') }}
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"
                                for="coupon_per_user">{{ __('shop::admin.discounts.coupons.per_user_limit') }}</label>
                            <input class="form-control" id="coupon_per_user" name="per_user_limit" type="number"
                                min="1" max="1000000" value="{{ $value('per_user_limit') }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="coupon_period">{{ __('shop::admin.products.limits.limit_period') }}</label>
                            <select class="form-select" id="coupon_period" name="period">
                                <option value="">{{ __('shop::admin.products.limits.all_time') }}</option>
                                @foreach (['day', 'week', 'month', 'year'] as $period)
                                    <option value="{{ $period }}" @selected($value('period') === $period)>
                                        {{ __('shop::admin.products.limits.periods.' . $period) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-footer">
                <button class="btn btn-primary" type="submit">{{ __('shop::messages.actions.save') }}</button>
            </div>
        </section>
    </form>
    @push('scripts')
        <script type="module" src="{{ route('site.extensions.assets.show', ['id' => 'shop', 'asset' => 'admin.js']) }}"></script>
    @endpush
@endsection
