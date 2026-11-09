@use('Illuminate\Support\Collection')
@use('KraftInk\Plugins\Shop\Models\Catalog\Category')
@use('App\Models\Servers\Server')
@use('KraftInk\Plugins\Shop\Models\Pricing\UpgradeGroup')
@use('App\Kraft\Currencies\CurrencyRegistry')
@use('App\Kraft\LocalePolicy')
@use('App\Kraft\Wallet\Money')
@use('App\Kraft\Wallet\Wallet')
@php
    /** @var Collection<int, Category> $categories */
    /** @var Collection<int, Server> $servers */
    /** @var Collection<int, UpgradeGroup> $upgradeGroups */
@endphp
@extends('admin.layouts.layout')
@section('title', $product ? __('shop::admin.products.edit_product') . ' · ' . $product->name :
    __('shop::admin.products.fields.new_product'))
@section('actions')
    <a class="btn btn-outline-secondary" href="{{ route('plugins.shop.admin.catalog.index') }}">
        {{ __('shop::admin.products.fields.products') }}</a>
@endsection
@section('content')
    @php
        $value = fn($key, $fallback = null) => old($key, $product?->{$key} ?? $fallback);
        $translationsEnabled = app(LocalePolicy::class)->allowsChoice();
    @endphp
    <form data-ajax="true" data-redirect-on-upload="true"
        @unless ($product) data-redirect-url="response" @endunless method="post"
        action="{{ $product ? route('plugins.shop.admin.products.update', $product->id) : route('plugins.shop.admin.products.store') }}"
        enctype="multipart/form-data" data-shop-product data-shop-errors='@json($errors->keys())'>
        @csrf
        @if ($product)
            @method('PUT')
        @endif
        <section class="card">
            <div class="card-header p-0">
                <ul class="nav nav-tabs card-header-tabs m-0" role="tablist">
                    @foreach (['details', 'pricing', 'delivery', 'limits', 'translations', 'variables'] as $panel)
                        @continue($panel === 'translations' && !$translationsEnabled)
                        <li class="nav-item" role="presentation"
                            @if (in_array($panel, ['delivery', 'variables'])) data-shop-product-tab @endif>
                            <button @class(['nav-link', 'active' => $loop->first]) id="product-tab-{{ $panel }}" type="button"
                                data-bs-toggle="tab" data-bs-target="#product-{{ $panel }}" role="tab"
                                aria-controls="product-{{ $panel }}"
                                aria-selected="{{ $loop->first ? 'true' : 'false' }}">{{ __('shop::admin.products.tabs.' . $panel) }}</button>
                        </li>
                    @endforeach
                </ul>
            </div>
            <div class="card-body tab-content">
                <div class="tab-pane fade show active" id="product-details" role="tabpanel"
                    aria-labelledby="product-tab-details">
                    <div class="mb-3">
                        <label class="form-label" for="product_kind">{{ __('shop::commerce.giftcards.kind') }}</label>
                        <select class="form-select" id="product_kind" name="kind" data-shop-kind>
                            <option value="product" @selected($value('kind', 'product') === 'product')>
                                {{ __('shop::commerce.giftcards.product') }}
                            </option>
                            <option value="giftcard" @selected($value('kind', 'product') === 'giftcard')>
                                {{ __('shop::commerce.giftcards.giftcard') }}
                            </option>
                        </select>
                        <div class="form-text" data-shop-giftcard-only @if ($value('kind', 'product') !== 'giftcard') hidden @endif>
                            {{ __('shop::messages.account.giftcards.kind_help') }}
                        </div>
                    </div>
                    <div class="mb-4" data-shop-giftcard-only>
                        <input type="hidden" name="upgrade_weight" value="0">
                        <label class="form-label" for="giftcard_days">{{ __('shop::commerce.giftcards.days') }}</label>
                        <input class="form-control" id="giftcard_days" name="giftcard_days" type="number" min="1"
                            max="36500" value="{{ $value('giftcard_days') }}">
                        <div class="form-text">
                            {{ __('shop::messages.account.giftcards.days_help') }}
                        </div>
                        @if ($product && ($product->kind ?? 'product') !== 'giftcard')
                            <p class="small text-warning mt-2 mb-0">
                                {{ __('shop::messages.account.giftcards.switch_help') }}</p>
                        @endif
                    </div>
                    <div class="row g-3">
                        <div class="col-lg-8 mb-3">
                            <label class="form-label" for="product_name">{{ __('shop::messages.labels.name') }}</label>
                            <input class="form-control" id="product_name" name="name" value="{{ $value('name') }}"
                                maxlength="180" required>
                        </div>
                        <div class="col-lg-4 mb-3">
                            @if ($translationsEnabled)
                                <label class="form-label"
                                    for="product_locale">{{ __('shop::admin.products.fields.primary_language') }}</label>
                                <select class="form-select" id="product_locale" name="locale" data-shop-locale>
                                    @foreach (config('kraft.locales') as $code => $label)
                                        <option value="{{ $code }}" @selected($value('locale', app()->getLocale()) === $code)>
                                            {{ $label }}
                                        </option>
                                    @endforeach
                                </select>
                            @else
                                <input type="hidden" name="locale"
                                    value="{{ $product?->locale ?? app(LocalePolicy::class)->defaultLocale() }}">
                            @endif
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"
                            for="product_slug">{{ __('shop::admin.products.fields.product_address') }}</label>
                        <div class="input-group">
                            <span
                                class="input-group-text">{{ url('/shop/products/' . ($product?->id ?? '…') . '/') }}/</span>
                            <input class="form-control" id="product_slug" name="slug" value="{{ $value('slug') }}"
                                maxlength="180" pattern="[a-z0-9]+(-[a-z0-9]+)*" data-slug-source="[name=name]">
                        </div>
                        <div class="form-text">
                            {{ $product ? __('shop::admin.products.fields.slug_help') : __('shop::admin.products.fields.slug_new_help') }}
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="product_category">{{ __('shop::messages.labels.category') }}</label>
                        <select class="form-select" id="product_category" name="category_id">
                            <option value="">{{ __('shop::admin.products.fields.no_category') }}</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}" @selected((int) $value('category_id') === $category->id)>{{ $category->name }}
                                    @if ($translationsEnabled)
                                        · {{ strtoupper($category->locale) }}
                                    @endif
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="form-label"
                            for="product_description">{{ __('shop::messages.labels.description') }}</label>
                        <textarea class="form-control" id="product_description" name="description" rows="12" data-editor="tinymce">{{ $value('description') }}</textarea>
                    </div>
                    @include('shop::admin.catalog.partials.image_fields', ['imageRecord' => $product])
                </div>
                <div class="tab-pane fade" id="product-pricing" role="tabpanel" aria-labelledby="product-tab-pricing">
                    <div class="row g-3">
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="product_price">{{ __('shop::admin.catalog.price') }}</label>
                            <input class="form-control" id="product_price" name="price_amount" inputmode="decimal"
                                pattern="[0-9]+([.,][0-9]{1,2})?"
                                value="{{ old('price_amount', $product ? Money::decimal((int) $product->price_minor) : '') }}"
                                required>
                            <div class="form-text">
                                {{ __('shop::admin.products.fields.money_help') }}
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"
                                for="product_currency">{{ __('shop::messages.labels.currency') }}</label>
                            <select class="form-select" id="product_currency" name="currency" data-searchable-select
                                required>
                                @if (!app(CurrencyRegistry::class)->find((string) $value('currency', 'EUR')))
                                    <option value="{{ $value('currency', 'EUR') }}" selected disabled>
                                        {{ $value('currency', 'EUR') }}</option>
                                @endif
                                @foreach (app(CurrencyRegistry::class)->all() as $currency)
                                    <option value="{{ $currency->code }}" @selected($value('currency', 'EUR') === $currency->code)>
                                        {{ $currency->code }}{{ $currency->label() === $currency->code ? '' : ' · ' . $currency->label() }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="mb-3" data-shop-product-only>
                        <label class="form-label"
                            for="product_billing">{{ __('shop::admin.products.billing.billing_interval') }}</label>
                        <select class="form-select" id="product_billing" name="billing_interval" data-shop-billing>
                            <option value="">{{ __('shop::admin.products.billing.one_time') }}</option>
                            @foreach (['month', 'year'] as $interval)
                                <option value="{{ $interval }}" @selected($value('billing_interval') === $interval)>
                                    {{ __('shop::admin.products.billing.' . $interval) }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">
                            {{ __('shop::admin.products.fields.subscription_help') }}
                        </div>
                    </div>
                    <input type="hidden" name="custom_price" value="0">
                    <fieldset class="row g-3 mb-3" data-shop-subscription-options>
                        <div class="col-md-4">
                            <label class="form-label"
                                for="product_trial_days">{{ __('shop::subscriptions.trial_days') }}</label>
                            <input class="form-control" type="number" id="product_trial_days" name="trial_days"
                                min="0" max="90" value="{{ $value('trial_days', 0) }}">
                            <div class="form-text">{{ __('shop::subscriptions.trial_days_help') }}</div>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label"
                                for="product_subscription_group">{{ __('shop::subscriptions.group') }}</label>
                            <input class="form-control" id="product_subscription_group" name="subscription_group"
                                maxlength="80" pattern="[a-zA-Z0-9_-]+" value="{{ $value('subscription_group', '') }}">
                            <div class="form-text">{{ __('shop::subscriptions.group_help') }}</div>
                        </div>
                        <div class="col-12">
                            <div data-shop-trial-payment-method>
                                <input type="hidden" name="trial_requires_payment_method" value="0">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" id="product_trial_requires_payment_method"
                                        name="trial_requires_payment_method" type="checkbox" value="1"
                                        @checked($value('trial_requires_payment_method', true))>
                                    <label class="form-check-label" for="product_trial_requires_payment_method">
                                        {{ __('shop::subscriptions.trial_requires_payment_method') }}
                                    </label>
                                    <div class="form-text">
                                        {{ __('shop::subscriptions.trial_requires_payment_method_help') }}</div>
                                </div>
                            </div>
                            <p class="small text-body-secondary mb-0">{{ __('shop::subscriptions.reversible_help') }}</p>
                        </div>
                    </fieldset>
                    <div class="form-check mb-3">
                        <input class="form-check-input" id="product_custom_price" name="custom_price" type="checkbox"
                            value="1" @checked($value('custom_price', false)) data-shop-custom-price>
                        <label class="form-check-label"
                            for="product_custom_price">{{ __('shop::admin.products.fields.custom_price') }}</label>
                    </div>
                    <div class="row g-3 mb-4" data-shop-price-range>
                        <div class="col-md-6">
                            <label class="form-label"
                                for="minimum_price">{{ __('shop::admin.products.fields.minimum_price') }}</label>
                            <input class="form-control" id="minimum_price" name="minimum_price_amount"
                                inputmode="decimal" pattern="[0-9]+([.,][0-9]{1,2})?"
                                value="{{ old('minimum_price_amount', isset($product->minimum_price_minor) ? Money::decimal((int) $product->minimum_price_minor) : '') }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"
                                for="maximum_price">{{ __('shop::admin.products.fields.maximum_price') }}</label>
                            <input class="form-control" id="maximum_price" name="maximum_price_amount"
                                inputmode="decimal" pattern="[0-9]+([.,][0-9]{1,2})?"
                                value="{{ old('maximum_price_amount', isset($product->maximum_price_minor) ? Money::decimal((int) $product->maximum_price_minor) : '') }}">
                        </div>
                    </div>
                    @if (auth()->user()->hasPermission('wallet.manage'))
                        <fieldset class="border-top pt-3" data-shop-product-only>
                            <legend class="float-none w-auto fs-6 fw-semibold">
                                {{ __('shop::admin.products.fields.wallet_credit') }}
                            </legend>
                            <label class="form-label"
                                for="wallet_credit_amount">{{ __('shop::admin.products.fields.wallet_credit_amount') }}</label>
                            <div class="input-group">
                                <input class="form-control" id="wallet_credit_amount" name="wallet_credit_amount"
                                    inputmode="decimal" pattern="[0-9]+([.,][0-9]{1,2})?"
                                    value="{{ old('wallet_credit_amount', Money::decimal((int) ($product->wallet_credit_minor ?? 0))) }}">
                                <span class="input-group-text">{{ app(Wallet::class)->currency() }}</span>
                            </div>
                            <input type="hidden" name="wallet_currency" value="{{ app(Wallet::class)->currency() }}">
                            <div class="form-text">
                                {{ __('shop::commerce.wallet.credit_help') }}
                            </div>
                        </fieldset>
                    @endif
                </div>
                <div class="tab-pane fade" id="product-delivery" data-shop-product-only role="tabpanel"
                    aria-labelledby="product-tab-delivery">
                    <div class="mb-3">
                        <label class="form-label" for="product_role">{{ __('shop::admin.products.fields.role') }}</label>
                        <select class="form-select" id="product_role" name="role_id">
                            <option value="">{{ __('shop::admin.products.fields.no_role') }}</option>
                            @foreach ($roles as $role)
                                <option value="{{ $role->id }}" @selected((int) $value('role_id') === $role->id)>{{ $role->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-4" data-shop-one-time>
                        <label class="form-label"
                            for="role_days">{{ __('shop::admin.products.fields.role_days') }}</label>
                        <input class="form-control" id="role_days" name="role_days" type="number" min="1"
                            max="36500" value="{{ $value('role_days') }}">
                    </div>
                    <div class="mb-4">
                        <label class="form-label"
                            for="product_download">{{ __('shop::admin.products.fields.download_file') }}</label>
                        <input class="form-control" id="product_download" type="file" name="download"
                            accept=".zip,.pdf,.txt,.png,.jpg,.jpeg,.webp">
                        @if ($product?->download_path)
                            <div class="form-text">
                                {{ __('shop::admin.products.fields.existing_download') }}
                            </div>
                        @endif
                    </div>
                    <input type="hidden" name="uses_codes" value="0">
                    <div class="form-check mb-4" data-shop-one-time>
                        <input class="form-check-input" id="product_codes" name="uses_codes" type="checkbox"
                            value="1" @checked($value('uses_codes', false))>
                        <label class="form-check-label"
                            for="product_codes">{{ __('shop::admin.products.fields.uses_codes') }}</label>
                        <div class="form-text">
                            {{ __('shop::admin.products.fields.codes_help') }}
                        </div>
                    </div>
                    <fieldset class="border-top pt-3">
                        <legend class="float-none w-auto fs-6 fw-semibold">
                            {{ __('shop::messages.site.orders.deliveries') }}</legend>
                        <div class="mb-3">
                            <label class="form-label"
                                for="product_server">{{ __('shop::integrations.servers.target_server') }}</label>
                            <select class="form-select" id="product_server" name="server_id">
                                <option value="">{{ __('shop::integrations.servers.default_server') }}</option>
                                @foreach ($servers as $server)
                                    <option value="{{ $server->id }}" @selected((int) $value('server_id') === $server->id)>{{ $server->name }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text">
                                {{ __('shop::integrations.servers.target_help') }}
                            </div>
                        </div>
                        <input type="hidden" name="server_choices_present" value="1">
                        <fieldset class="mb-3">
                            <legend class="fs-6">{{ __('shop::admin.products.fields.server_choices') }}</legend>
                            <p class="form-text">{{ __('shop::admin.products.fields.server_choices_help') }}</p>
                            @foreach ($servers as $server)
                                <div class="form-check">
                                    <input class="form-check-input" id="choice_server_{{ $server->id }}"
                                        name="server_choices[]" type="checkbox" value="{{ $server->id }}"
                                        @checked(in_array($server->id, array_map('intval', old('server_choices', $serverChoices)), true))>
                                    <label class="form-check-label"
                                        for="choice_server_{{ $server->id }}">{{ $server->name }}</label>
                                </div>
                            @endforeach
                        </fieldset>
                        <input type="hidden" name="requires_online" value="0">
                        <div class="form-check mb-3">
                            <input class="form-check-input" id="requires_online" name="requires_online" type="checkbox"
                                value="1" @checked($value('requires_online', false))>
                            <label class="form-check-label"
                                for="requires_online">{{ __('shop::integrations.servers.requires_online') }}</label>
                        </div>
                        <div class="mb-3">
                            <label class="form-label"
                                for="product_commands">{{ __('shop::admin.products.fields.commands') }}</label>
                            <textarea class="form-control font-monospace" id="product_commands" name="commands" rows="5"
                                maxlength="10000">{{ old('commands', implode("\n", json_decode($product?->commands ?: '[]', true))) }}</textarea>
                        </div>
                        <div>
                            <label class="form-label"
                                for="refund_commands">{{ __('shop::commerce.refunds.refund_commands') }}</label>
                            <textarea class="form-control font-monospace" id="refund_commands" name="refund_commands" rows="4"
                                maxlength="10000">{{ old('refund_commands', implode("\n", json_decode($product?->refund_commands ?: '[]', true))) }}</textarea>
                            <div class="form-text">
                                {{ __('shop::commerce.refunds.refund_commands_help') }}
                            </div>
                        </div>
                    </fieldset>
                </div>
                <div class="tab-pane fade" id="product-limits" role="tabpanel" aria-labelledby="product-tab-limits">
                    <div class="row g-3">
                        <div class="col-md-6 mb-3" data-shop-one-time>
                            <label class="form-label" for="product_stock">{{ __('shop::admin.catalog.stock') }}</label>
                            <input class="form-control" id="product_stock" name="stock" type="number" min="0"
                                max="1000000" value="{{ $value('stock') }}">
                            <div class="form-text">
                                {{ __('shop::admin.catalog.unlimited_help') }}
                            </div>
                        </div>
                        <div class="col-md-6 mb-3" data-shop-one-time>
                            <label class="form-label"
                                for="limit_per_user">{{ __('shop::admin.products.limits.per_member_limit') }}</label>
                            <input class="form-control" id="limit_per_user" name="limit_per_user" type="number"
                                min="1" max="1000000" value="{{ $value('limit_per_user') }}">
                            <div class="form-text">
                                {{ __('shop::admin.catalog.unlimited_help') }}
                            </div>
                        </div>
                        <div class="col-md-6 mb-3" data-shop-one-time>
                            <label class="form-label"
                                for="global_limit">{{ __('shop::admin.products.limits.global_limit') }}</label>
                            <input class="form-control" id="global_limit" name="global_limit" type="number"
                                min="1" max="1000000" value="{{ $value('global_limit') }}">
                            <div class="form-text">
                                {{ __('shop::admin.catalog.unlimited_help') }}
                            </div>
                        </div>
                        <div class="col-md-6 mb-3" data-shop-one-time>
                            <label class="form-label"
                                for="limit_period">{{ __('shop::admin.products.limits.limit_period') }}</label>
                            <select class="form-select" id="limit_period" name="limit_period">
                                <option value="">{{ __('shop::admin.products.limits.all_time') }}</option>
                                @foreach (['day', 'week', 'month', 'year'] as $period)
                                    <option value="{{ $period }}" @selected($value('limit_period') === $period)>
                                        {{ __('shop::admin.products.limits.periods.' . $period) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label"
                            for="required_permission">{{ __('shop::admin.products.fields.permission') }}</label>
                        <input class="form-control" id="required_permission" name="required_permission"
                            value="{{ $value('required_permission') }}" maxlength="100">
                    </div>
                    <fieldset class="border-top pt-3" data-shop-one-time data-shop-product-only>
                        <legend class="float-none w-auto fs-6 fw-semibold">
                            {{ __('shop::admin.products.fields.rank_upgrades') }}</legend>
                        <div class="row g-3">
                            <div class="col-md-6 mb-3">
                                <label class="form-label"
                                    for="upgrade_group">{{ __('shop::admin.products.fields.upgrade_group') }}</label>
                                <select class="form-select" id="upgrade_group" name="upgrade_group">
                                    <option value="">{{ __('shop::commerce.upgrades.none') }}</option>
                                    @foreach ($upgradeGroups as $group)
                                        <option value="{{ $group->slug }}" data-currency="{{ $group->currency }}"
                                            @selected($value('upgrade_group') === $group->slug)>{{ $group->name }} · {{ $group->currency }}
                                        </option>
                                    @endforeach
                                </select>
                                <a class="form-text d-block"
                                    href="{{ route('plugins.shop.admin.pricing.index') }}"
                                    target="_blank">{{ __('shop::commerce.upgrades.manage_groups') }}</a>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label"
                                    for="upgrade_weight">{{ __('shop::admin.products.fields.upgrade_weight') }}</label>
                                <input class="form-control" id="upgrade_weight" name="upgrade_weight" type="number"
                                    min="0" max="100000" value="{{ $value('upgrade_weight', 0) }}" required>
                            </div>
                        </div>
                    </fieldset>
                    <div class="row g-3 border-top pt-3">
                        <div class="col-md-6">
                            <label class="form-label"
                                for="product_position">{{ __('shop::messages.labels.position') }}</label>
                            <input class="form-control" id="product_position" name="position" type="number"
                                min="0" max="10000" value="{{ $value('position', 0) }}" required>
                        </div>
                        <div class="col-md-6">
                            <input type="hidden" name="enabled" value="0">
                            <div class="form-check mt-md-4">
                                <input class="form-check-input" id="product_enabled" name="enabled" type="checkbox"
                                    value="1" @checked($value('enabled', true))>
                                <label class="form-check-label"
                                    for="product_enabled">{{ __('shop::messages.states.enabled') }}</label>
                            </div>
                        </div>
                    </div>
                </div>
                @if ($translationsEnabled)
                    <div class="tab-pane fade" id="product-translations" role="tabpanel"
                        aria-labelledby="product-tab-translations">
                        @include('shop::admin.catalog.partials.translation_fields', [
                            'translationRecord' => $product,
                            'translationLimit' => 100000,
                        ])
                    </div>
                @endif
                <div class="tab-pane fade" id="product-variables" data-shop-product-only role="tabpanel"
                    aria-labelledby="product-tab-variables">
                    @php
                        $definitions = old('variables', $product?->variables ?? []) ?? [];
                    @endphp
                    <input type="hidden" name="variables_present" value="1">
                    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start gap-3 mb-3">
                        <p class="text-body-secondary mb-0">{{ __('shop::admin.products.variables.variables_help') }}</p>
                        <button class="btn btn-outline-primary flex-shrink-0" type="button"
                            data-variable-add>{{ __('shop::admin.products.variables.add_variable') }}</button>
                    </div>
                    <div class="alert alert-light border">
                        {{ __('shop::admin.products.variables.variable_safety') }}
                    </div>
                    <div class="d-flex flex-column gap-3" data-variable-list>
                        @foreach ($definitions as $index => $definition)
                            @include('shop::admin.products.partials.variable_row', [
                                'index' => $index,
                                'definition' => $definition,
                            ])
                        @endforeach
                    </div>
                    <p class="text-body-secondary mt-3" data-variables-empty
                        @if (count($definitions)) hidden @endif>
                        {{ __('shop::admin.products.variables.no_variables') }}</p>
                    <template data-variable-template>
                        @include('shop::admin.products.partials.variable_row', [
                            'index' => '__INDEX__',
                            'definition' => [],
                        ])
                    </template>
                </div>
            </div>
            <div class="card-footer d-flex flex-wrap gap-2">
                <button class="btn btn-primary" type="submit">{{ __('shop::messages.actions.save') }}</button>
                @if ($product && $product->enabled)
                    <a class="btn btn-outline-secondary"
                        href="{{ route('plugins.shop.public.products.show', ['id' => $product->id, 'slug' => $product->slug]) }}"
                        target="_blank" rel="noopener">{{ __('shop::admin.products.view_product') }}</a>
                @endif
            </div>
        </section>
    </form>
    @if ($product && ($product->kind ?? 'product') === 'product')
        <section class="card" data-shop-product-only>
            <div class="card-header">
                <h2 class="card-title">{{ __('shop::admin.products.fields.code_inventory') }}</h2>
            </div>
            <div class="card-body">
                <p>{{ __('shop::admin.products.fields.codes_count', ['available' => $availableCodes, 'total' => $codeCount]) }}
                </p>
                <button class="btn btn-outline-primary" type="button" data-bs-toggle="modal"
                    data-bs-target="#product-add-codes">{{ __('shop::admin.products.fields.add_codes') }}</button>
            </div>
        </section>
        <x-forms.modal id="product-add-codes" :title="__('shop::admin.products.fields.add_codes')" :action="route('plugins.shop.admin.products.codes.store', $product->id)">
            <label class="form-label" for="new_product_codes">{{ __('shop::admin.products.fields.add_codes') }}</label>
            <textarea class="form-control font-monospace" id="new_product_codes" name="codes" rows="10"
                maxlength="100000" required></textarea>
        </x-forms.modal>
    @endif
    @push('scripts')
        <script type="module" src="{{ route('site.extensions.assets.show', ['id' => 'shop', 'asset' => 'admin.js']) }}">
        </script>
    @endpush
@endsection
