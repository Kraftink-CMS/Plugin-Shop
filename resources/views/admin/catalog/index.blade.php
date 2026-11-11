@use('KraftInk\Plugins\Shop\Models\Catalog\Category')
@use('App\Kraft\Currencies\CurrencyRegistry')
@use('App\Kraft\SiteDates')
@use('App\Kraft\Wallet\Money')
@use('Illuminate\Support\Facades\Route')
@use('Illuminate\Support\Collection')
@php
    /** @var Collection<int, Category> $categoryOptions */
@endphp
@extends('admin.layouts.layout')
@section('title', __('shop::admin.catalog.title'))
@section('description', __('shop::admin.navigation.description'))
@section('actions')
    <span data-shop-catalogue-action="products" @if ($tab !== 'products') hidden @endif>
        <a class="btn btn-primary" href="{{ route('plugins.shop.admin.products.create') }}">{{ __('shop::admin.products.fields.new_product') }}</a>
    </span>
    @foreach (['categories', 'coupons'] as $actionSection)
        <span data-shop-catalogue-action="{{ $actionSection }}" @if ($tab !== $actionSection) hidden @endif>
            <a class="btn btn-primary" href="{{ route('plugins.shop.admin.' . $actionSection . '.create') }}">{{ __('shop::admin.navigation.add.' . $actionSection) }}</a>
        </span>
    @endforeach
    <span data-shop-catalogue-action="giftcards" @if ($tab !== 'giftcards') hidden @endif>
        <button class="btn btn-primary" type="button" data-bs-toggle="modal"
            data-bs-target="#shop-giftcards-new">{{ __('shop::admin.navigation.add.giftcards') }}</button>
    </span>
@endsection
@section('content')
    <ul class="nav nav-tabs" role="tablist" data-shop-catalogue data-shop-errors='@json($errors->keys())'>
        @foreach ($catalogueSections as $section)
            <li class="nav-item" role="presentation">
                <button @class(['nav-link', 'active' => $tab === $section]) id="catalogue-tab-{{ $section }}" type="button"
                    data-bs-toggle="tab" data-bs-target="#catalogue-{{ $section }}" data-catalogue-section="{{ $section }}"
                    role="tab" aria-controls="catalogue-{{ $section }}" aria-selected="{{ $tab === $section ? 'true' : 'false' }}">{{ __('shop::admin.navigation.tabs.' . $section) }}</button>
            </li>
        @endforeach
    </ul>
    <div class="tab-content">
        @foreach ($catalogueSections as $section)
            <div @class(['tab-pane fade', 'show active' => $tab === $section]) id="catalogue-{{ $section }}"
                role="tabpanel" aria-labelledby="catalogue-tab-{{ $section }}" tabindex="0">
                @if ($section !== 'payments')
                    <div id="shop-admin-catalogue-{{ $section }}" data-ajax-pagination data-table tabindex="-1" class="vstack gap-3">
                @endif
                @if ($section === 'products')
                    <section class="card">
                        <div class="card-body">
                            <x-tables.controls :paginator="$products" class="mb-3" />
                            <form method="get" action="{{ route('plugins.shop.admin.catalog.index') }}" class="row g-2">
                                <input type="hidden" name="tab" value="products">
                                <div class="col-lg-4">
                                    <input class="form-control" name="q" value="{{ request('q') }}"
                                        placeholder="{{ __('shop::admin.catalog.filters.search_products') }}"
                                        aria-label="{{ __('shop::admin.catalog.filters.search_products') }}" maxlength="100">
                                </div>
                                <div class="col-lg-3">
                                    <select class="form-select" name="category" aria-label="{{ __('shop::messages.labels.category') }}">
                                        <option value="">{{ __('shop::admin.catalog.filters.all_categories') }}</option>
                                        @foreach ($categoryOptions as $category)
                                            <option value="{{ $category->id }}" @selected((int) request('category') === $category->id)>{{ $category->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-lg-2">
                                    <select class="form-select" name="locale" aria-label="{{ __('shop::messages.labels.language') }}">
                                        <option value="">{{ __('shop::admin.catalog.filters.all_languages') }}</option>
                                        @foreach (config('kraft.locales') as $code => $label)
                                            <option value="{{ $code }}" @selected(request('locale') === $code)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-lg-2">
                                    <select class="form-select" name="status" aria-label="{{ __('shop::messages.labels.status') }}">
                                        <option value="">{{ __('shop::admin.catalog.filters.all_statuses') }}</option>
                                        @foreach (['active', 'inactive'] as $status)
                                            <option value="{{ $status }}" @selected(request('status') === $status)>{{ __('common.statuses.' . $status) }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <noscript>
                                    <div class="col-lg-1">
                                        <button class="btn btn-outline-secondary w-100" type="submit"
                                            aria-label="{{ __('common.actions.search') }}"><i class="bi bi-search" aria-hidden="true"></i></button>
                                    </div>
                                </noscript>
                            </form>
                        </div>
                        <div class="card-body p-0 table-responsive">
                            <table class="table align-middle mb-0">
                                <thead class="text-nowrap">
                                    <tr>
                                        <th scope="col" data-table-sort="name">{{ __('shop::messages.labels.name') }}</th>
                                        <th scope="col" data-table-sort="category">{{ __('shop::messages.labels.category') }}</th>
                                        <th>{{ __('shop::admin.catalog.price') }}</th>
                                        <th scope="col" data-table-sort="stock">{{ __('shop::admin.catalog.stock') }}</th>
                                        <th scope="col" data-table-sort="enabled">{{ __('shop::messages.labels.status') }}</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($products as $item)
                                        @php
                                            $editableProduct =
                                                (!$item->role_id || $roles->contains('id', (int) $item->role_id)) &&
                                                (!(int) ($item->wallet_credit_minor ?? 0) ||
                                                    auth()->user()->hasPermission('wallet.manage'));
                                        @endphp
                                        <tr>
                                            <td><strong>{{ $item->name }}</strong><small
                                                    class="d-block text-body-secondary">{{ strtoupper($item->locale) }}
                                                    @if ($item->billing_interval)
                                                        · {{ __('shop::admin.products.billing.' . $item->billing_interval) }}
                                                    @endif
                                                </small>
                                            </td>
                                            <td>{{ $item->category_name ?? '—' }}</td>
                                            <td>{{ Money::format((int) $item->price_minor) }} {{ $item->currency }}</td>
                                            <td>{{ $item->stock ?? __('shop::admin.catalog.unlimited') }}</td>
                                            <td><span
                                                    @class([
                                                        'badge',
                                                        'text-bg-success' => $item->enabled,
                                                        'text-bg-secondary' => !$item->enabled,
                                                    ])>{{ $item->enabled ? __('common.statuses.active') : __('common.statuses.inactive') }}</span>
                                            </td>
                                            <td class="text-end">
                                                @if ($editableProduct)
                                                    <div class="dropdown">
                                                        <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button"
                                                            data-bs-toggle="dropdown"
                                                            aria-expanded="false">{{ __('common.labels.actions') }}</button>
                                                        <ul class="dropdown-menu dropdown-menu-end">
                                                            <li><a class="dropdown-item"
                                                                    href="{{ route('plugins.shop.admin.products.edit', $item->id) }}">{{ __('common.actions.edit') }}</a>
                                                            </li>
                                                            <li>
                                                                <form data-ajax="true" data-redirect-url="response" method="post"
                                                                    action="{{ route('plugins.shop.admin.products.duplicate', $item->id) }}">
                                                                    @csrf
                                                                    <button class="dropdown-item"
                                                                        type="submit">{{ __('shop::admin.products.duplicate') }}</button>
                                                                </form>
                                                            </li>
                                                            <li>
                                                                <button class="dropdown-item text-danger" type="button"
                                                                    data-bs-toggle="modal"
                                                                    data-bs-target="#shop-product-delete-{{ $item->id }}">{{ __('common.actions.delete') }}</button>
                                                            </li>
                                                        </ul>
                                                    </div>
                                                @else
                                                    <span
                                                        class="small text-body-secondary">{{ __('shop::admin.products.protected_product') }}</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <x-tables.empty :colspan="6" :message="__('shop::admin.catalog.empty.no_products')" :description="__('shop::admin.catalog.empty.no_products_help')" :action="__('shop::admin.products.fields.new_product')"
                                            :href="route('plugins.shop.admin.products.create')" />
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        <div class="card-footer">
                            {{ $products->links() }}
                        </div>
                    </section>
                    @foreach ($products as $item)
                        @if (
                            (!$item->role_id || $roles->contains('id', (int) $item->role_id)) &&
                                (!(int) ($item->wallet_credit_minor ?? 0) || auth()->user()->hasPermission('wallet.manage')))
                            <x-forms.modal :id="'shop-product-delete-' . $item->id" :title="__('common.actions.delete')" :action="route('plugins.shop.admin.products.destroy', $item->id)" method="DELETE" :submit="__('common.actions.delete')"
                                size="sm">
                                <p>{{ __('shop::admin.products.delete_product') }}</p>
                                <strong>{{ $item->name }}</strong>
                            </x-forms.modal>
                        @endif
                    @endforeach
                @elseif ($section === 'orders')
                    <section class="card">
                        <x-tables.controls :paginator="$orders" container-class="card-body" />
                        <div class="card-body p-0 table-responsive">
                            <table class="table align-middle mb-0">
                                <thead class="text-nowrap">
                                    <tr>
                                        <th scope="col" data-table-sort="id">{{ __('shop::messages.site.orders.order') }}</th>
                                        <th>{{ __('shop::messages.site.orders.total') }}</th>
                                        <th scope="col" data-table-sort="provider">{{ __('shop::messages.site.cart.payment_method') }}</th>
                                        <th scope="col" data-table-sort="status">{{ __('shop::messages.labels.status') }}</th>
                                        <th scope="col" data-table-sort="created_at">{{ __('shop::messages.labels.date') }}</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($orders as $order)
                                        <tr>
                                            <td><a href="{{ route('plugins.shop.admin.orders.show', $order->reference) }}">#{{ $order->id }}</a></td>
                                            <td>{{ Money::format((int) $order->total_minor + (int) $order->giftcard_minor + (int) ($order->wallet_minor ?? 0)) }} {{ $order->currency }}</td>
                                            <td>{{ $order->provider }}</td>
                                            <td><span class="badge text-bg-secondary">{{ __('shop::messages.states.' . $order->status) }}</span>
                                            </td>
                                            <td>{{ app(SiteDates::class)->format($order->created_at) }}</td>
                                            <td class="text-end"><a class="btn btn-outline-secondary btn-sm"
                                                    href="{{ route('plugins.shop.admin.orders.show', $order->reference) }}">{{ __('shop::admin.orders.view_order') }}</a>
                                            </td>
                                        </tr>
                                    @empty
                                        <x-tables.empty :colspan="6" :message="__('shop::admin.catalog.empty.no_orders')" />
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        <div class="card-footer">
                            {{ $orders->links() }}
                        </div>
                    </section>
                @elseif ($section === 'categories')
                    <section class="card">
                        <x-tables.controls :paginator="$categories" container-class="card-body" />
                        <div class="card-body p-0 table-responsive">
                            <table class="table align-middle mb-0">
                                <thead class="text-nowrap">
                                    <tr>
                                        <th scope="col" data-table-sort="name">{{ __('shop::messages.labels.name') }}</th>
                                        <th scope="col" data-table-sort="parent">{{ __('shop::admin.categories.parent_category') }}</th>
                                        <th scope="col" data-table-sort="locale">{{ __('shop::messages.labels.language') }}</th>
                                        <th scope="col" data-table-sort="position">{{ __('shop::messages.labels.position') }}</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($categories as $category)
                                        <tr>
                                            <td><strong>{{ $category->name }}</strong></td>
                                            <td>{{ $category->parent_name ?? '—' }}</td>
                                            <td>{{ strtoupper($category->locale) }}</td>
                                            <td>{{ $category->position }}</td>
                                            <td class="text-end">
                                                <div class="dropdown">
                                                    <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button"
                                                        data-bs-toggle="dropdown" aria-expanded="false">{{ __('common.labels.actions') }}</button>
                                                    <ul class="dropdown-menu dropdown-menu-end">
                                                        <li><a class="dropdown-item"
                                                                href="{{ route('plugins.shop.admin.categories.edit', $category->id) }}">{{ __('common.actions.edit') }}</a>
                                                        </li>
                                                        <li>
                                                            <button class="dropdown-item text-danger" type="button" data-bs-toggle="modal"
                                                                data-bs-target="#shop-category-delete-{{ $category->id }}">{{ __('common.actions.delete') }}</button>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </td>
                                        </tr>
                                    @empty
                                        <x-tables.empty :colspan="5" :message="__('shop::admin.catalog.empty.no_categories')" :action="__('shop::admin.navigation.add.categories')" :href="route('plugins.shop.admin.categories.create')" />
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        <div class="card-footer">
                            {{ $categories->links() }}
                        </div>
                    </section>
                    @foreach ($categories as $category)
                        <x-forms.modal :id="'shop-category-delete-' . $category->id" :title="__('common.actions.delete')" :action="route('plugins.shop.admin.categories.destroy', $category->id)" method="DELETE" :submit="__('common.actions.delete')"
                            size="sm">
                            <p>{{ __('shop::admin.categories.delete_category') }}</p>
                            <strong>{{ $category->name }}</strong>
                        </x-forms.modal>
                    @endforeach
                @elseif ($section === 'coupons')
                    <section class="card">
                        <x-tables.controls :paginator="$coupons" container-class="card-body" />
                        <div class="card-body p-0 table-responsive">
                            <table class="table align-middle mb-0">
                                <thead class="text-nowrap">
                                    <tr>
                                        <th scope="col" data-table-sort="code">{{ __('shop::messages.site.cart.coupon') }}</th>
                                        <th>{{ __('shop::messages.site.orders.discount') }}</th>
                                        <th scope="col" data-table-sort="expires_at">{{ __('shop::admin.discounts.coupons.expires_at') }}</th>
                                        <th scope="col" data-table-sort="enabled">{{ __('shop::messages.labels.status') }}</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($coupons as $coupon)
                                        <tr>
                                            <td><strong>{{ $coupon->code }}</strong></td>
                                            <td>{{ $coupon->type === 'percent' ? Money::decimal((int) $coupon->amount) . ' %' : Money::format((int) $coupon->amount) . ' ' . $coupon->currency }}
                                            </td>
                                            <td>{{ app(SiteDates::class)->format($coupon->expires_at) }}
                                            </td>
                                            <td><span
                                                    @class([
                                                        'badge',
                                                        'text-bg-success' => $coupon->enabled,
                                                        'text-bg-secondary' => !$coupon->enabled,
                                                    ])>{{ $coupon->enabled ? __('common.statuses.active') : __('common.statuses.inactive') }}</span>
                                            </td>
                                            <td class="text-end"><a class="btn btn-outline-secondary btn-sm"
                                                    href="{{ route('plugins.shop.admin.coupons.edit', $coupon->id) }}">{{ __('common.actions.edit') }}</a></td>
                                        </tr>
                                    @empty
                                        <x-tables.empty :colspan="5" :message="__('shop::admin.catalog.empty.no_coupons')" :action="__('shop::admin.navigation.add.coupons')" :href="route('plugins.shop.admin.coupons.create')" />
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        <div class="card-footer">
                            {{ $coupons->links() }}
                        </div>
                    </section>
                @elseif ($section === 'giftcards')
                    <section class="card">
                        <x-tables.controls :paginator="$giftcards" container-class="card-body" />
                        <div class="card-body p-0 table-responsive">
                            <table class="table align-middle mb-0">
                                <thead class="text-nowrap">
                                    <tr>
                                        <th scope="col" data-table-sort="last_four">{{ __('shop::messages.site.cart.giftcard') }}</th>
                                        <th scope="col" data-table-sort="balance_minor">{{ __('shop::admin.giftcards.balance') }}</th>
                                        <th scope="col" data-table-sort="expires_at">{{ __('shop::admin.discounts.coupons.expires_at') }}</th>
                                        <th scope="col" data-table-sort="enabled">{{ __('shop::messages.labels.status') }}</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($giftcards as $giftcard)
                                        <tr>
                                            <td><strong>•••• {{ $giftcard->last_four }}</strong></td>
                                            <td>{{ Money::format((int) $giftcard->balance_minor) }}
                                                {{ $giftcard->currency }}</td>
                                            <td>{{ app(SiteDates::class)->format($giftcard->expires_at) }}
                                            </td>
                                            <td><span
                                                    @class([
                                                        'badge',
                                                        'text-bg-success' => $giftcard->enabled,
                                                        'text-bg-secondary' => !$giftcard->enabled,
                                                    ])>{{ $giftcard->enabled ? __('common.statuses.active') : __('common.statuses.inactive') }}</span>
                                            </td>
                                            <td class="text-end">
                                                @if ($giftcard->enabled)
                                                    <button class="btn btn-outline-danger btn-sm" type="button" data-bs-toggle="modal"
                                                        data-bs-target="#shop-giftcard-revoke-{{ $giftcard->id }}">{{ __('shop::commerce.giftcards.revoke_giftcard') }}</button>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <x-tables.empty :colspan="5" :message="__('shop::admin.catalog.empty.no_giftcards')" :action="__('shop::commerce.giftcards.create_giftcard')" modal="shop-giftcards-new" />
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        <div class="card-footer">
                            {{ $giftcards->links() }}
                        </div>
                    </section>
                    <x-forms.modal id="shop-giftcards-new" :title="__('shop::commerce.giftcards.create_giftcard')" :action="route('plugins.shop.admin.giftcards.store')" :submit="__('shop::messages.actions.create')">
                        <input type="hidden" name="catalogue_tab" value="giftcards">
                        <div class="mb-3">
                            <label class="form-label" for="giftcard_balance">{{ __('shop::admin.giftcards.balance') }}</label>
                            <input class="form-control" id="giftcard_balance" name="balance_amount" value="{{ old('balance_amount') }}"
                                inputmode="decimal" pattern="[0-9]+([.,][0-9]{1,2})?" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="giftcard_currency">{{ __('shop::messages.labels.currency') }}</label>
                            <select class="form-select" id="giftcard_currency" name="currency" data-searchable-select>
                                @foreach (app(CurrencyRegistry::class)->all() as $currency)
                                    <option value="{{ $currency->code }}" @selected(old('currency', 'EUR') === $currency->code)>{{ $currency->code }}{{ $currency->label() === $currency->code ? '' : ' · ' . $currency->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="form-label" for="giftcard_expires">{{ __('shop::admin.discounts.coupons.expires_at') }}</label>
                            <x-forms.local-datetime name="expires_at" id="giftcard_expires" />
                        </div>
                        @include('partials.forms.sensitive-fields')
                    </x-forms.modal>
                    @foreach ($giftcards as $giftcard)
                        @if ($giftcard->enabled)
                            <x-forms.modal :id="'shop-giftcard-revoke-' . $giftcard->id" :title="__('shop::commerce.giftcards.revoke_giftcard')" :action="route('plugins.shop.admin.giftcards.destroy', $giftcard->id)" method="DELETE" :submit="__('shop::commerce.giftcards.revoke_giftcard')"
                                size="sm">
                                <p>{{ __('shop::admin.giftcards.revoke_giftcard') }}</p>
                                <strong>•••• {{ $giftcard->last_four }}</strong>
                                <input type="hidden" name="catalogue_tab" value="giftcards">
                                @include('partials.forms.sensitive-fields')
                            </x-forms.modal>
                        @endif
                    @endforeach
                @elseif ($section === 'payments')
                    @if (auth()->user()->hasPermission('shop.finance.configure'))
                        <section class="card">
                                <div class="card-body">
                                    <p>{{ __('shop::commerce.payments.settings.payments_help') }}</p>
                                    <div class="accordion" id="shop-gateways">
                                        @foreach ($gateways as $gateway)
                                            @php
                                                $gatewayPolicy = $paymentPolicies[$gateway->id()] ?? [];
                                                $fields = $gateway->configurationFields();
                                                $webhookField = $webhookFields[$gateway->id()] ?? null;
                                                $gatewayConfigured = $gateway->configured();
                                                $activationId = 'gateway-enabled-' . $gateway->id();
                                                $gatewayErrors = collect($errors->keys())->contains(
                                                    fn($key) => str_starts_with($key, 'gateways.' . $gateway->id() . '.'),
                                                );
                                                $gatewayOpen = $gatewayErrors || session('shop_payment_gateway') === $gateway->id();
                                            @endphp
                                            <div class="accordion-item">
                                                <h2 class="accordion-header">
                                                    <button @class([
                                                        'accordion-button d-flex gap-2',
                                                        'collapsed' => !$gatewayOpen,
                                                    ]) type="button" data-bs-toggle="collapse"
                                                        data-bs-target="#gateway-{{ $gateway->id() }}"
                                                        aria-expanded="{{ $gatewayOpen ? 'true' : 'false' }}"
                                                        aria-controls="gateway-{{ $gateway->id() }}">{{ $gateway->name() }} <span
                                                            class="badge text-bg-secondary">{{ $gatewayConfigured ? __('shop::messages.states.configured') : __('shop::messages.states.not_configured') }}</span></button>
                                                </h2>
                                                <div @class(['accordion-collapse collapse', 'show' => $gatewayOpen]) id="gateway-{{ $gateway->id() }}"
                                                    data-bs-parent="#shop-gateways">
                                                    <div class="accordion-body">
                                                        <input type="hidden" name="gateways[{{ $gateway->id() }}][enabled]" value="0" form="shop-payment-settings">
                                                        <div class="form-check form-switch mb-3">
                                                            <input class="form-check-input" id="{{ $activationId }}"
                                                                type="checkbox" role="switch" name="gateways[{{ $gateway->id() }}][enabled]"
                                                                @if (!$gatewayConfigured) aria-describedby="{{ $activationId }}-help" @endif
                                                                value="1" form="shop-payment-settings" @checked($registry->enabled($gateway->id()))>
                                                            <label class="form-check-label"
                                                                for="{{ $activationId }}">{{ __('shop::commerce.payments.settings.offer_gateway') }}</label>
                                                            @unless ($gatewayConfigured)
                                                                <div class="form-text" id="{{ $activationId }}-help">{{ __('shop::commerce.payments.settings.gateway_requires_configuration') }}</div>
                                                            @endunless
                                                        </div>
                                                        @if ($webhookField)
                                                            @include('shop::admin.catalog.webhook-setup', ['setup' => $webhookStates[$gateway->id()]])
                                                        @else
                                                            @foreach ($fields as $key => $field)
                                                                @include('shop::admin.catalog.gateway-field', ['fieldForm' => 'shop-payment-settings'])
                                                            @endforeach
                                                        @endif
                                                        @if (in_array($gateway->id(), ['paysafecard', 'paymentwall'], true))
                                                            <p class="form-text mt-3">{{ __('shop::commerce.payments.providers.' . $gateway->id() . '_help') }}</p>
                                                            <p class="form-text">{{ __('shop::commerce.payments.providers.one_time_only') }}</p>
                                                        @endif
                                                        @if (!$webhookField && $gateway->id() !== 'paysafecard' && Route::has('plugins.shop.webhooks.' . $gateway->id() . '.handle'))
                                                            @include('shop::admin.catalog.webhook-url')
                                                        @endif
                                                        <fieldset class="mt-4">
                                                            <legend class="h5">{{ __('shop::commerce.payments.policy.conditions') }}</legend>
                                                            @if (in_array('zero_total', $gateway->capabilities(), true))
                                                                <p class="text-body-secondary mb-0">
                                                                    {{ __('shop::commerce.payments.policy.fully_covered_help') }}</p>
                                                            @else
                                                                <p class="text-body-secondary">{{ __('shop::commerce.payments.policy.limits_help') }}
                                                                </p>
                                                                <div class="table-responsive mb-3">
                                                                    <table class="table align-middle mb-0">
                                                                        <thead class="text-nowrap">
                                                                            <tr>
                                                                                <th scope="col">
                                                                                    {{ __('shop::commerce.payments.policy.currency') }}</th>
                                                                                <th scope="col">{{ __('shop::commerce.payments.policy.minimum') }}
                                                                                </th>
                                                                                <th scope="col">{{ __('shop::commerce.payments.policy.maximum') }}
                                                                                </th>
                                                                            </tr>
                                                                        </thead>
                                                                        <tbody>
                                                                            @foreach ($gatewayPolicy['currencies'] ?? [] as $currency)
                                                                                <tr>
                                                                                    <th scope="row">{{ $currency }}</th>
                                                                                    @foreach (['minimum', 'maximum'] as $limit)
                                                                                        <td>
                                                                                            <label class="visually-hidden"
                                                                                                for="gateway-{{ $gateway->id() }}-{{ $currency }}-{{ $limit }}">{{ __('shop::commerce.payments.policy.' . $limit . '_currency', ['currency' => $currency]) }}</label>
                                                                                            <input class="form-control"
                                                                                                id="gateway-{{ $gateway->id() }}-{{ $currency }}-{{ $limit }}"
                                                                                                type="text" inputmode="decimal"
                                                                                                maxlength="18" form="shop-payment-settings"
                                                                                                name="gateways[{{ $gateway->id() }}][policy][limits][{{ $currency }}][{{ $limit }}]"
                                                                                                value="{{ old('gateways.' . $gateway->id() . '.policy.limits.' . $currency . '.' . $limit, $gatewayPolicy['limits'][$currency][$limit] ?? '') }}"
                                                                                                placeholder="{{ __('shop::commerce.payments.policy.no_limit') }}">
                                                                                        </td>
                                                                                    @endforeach
                                                                                </tr>
                                                                            @endforeach
                                                                        </tbody>
                                                                    </table>
                                                                </div>
                                                                @foreach (['allow_giftcard' => 'can_use_giftcard', 'allow_wallet' => 'can_mix_wallet'] as $option => $capability)
                                                                    @if ($gatewayPolicy[$capability] ?? false)
                                                                        <input type="hidden"
                                                                            name="gateways[{{ $gateway->id() }}][policy][{{ $option }}]"
                                                                            value="0" form="shop-payment-settings">
                                                                        <div class="form-check mb-2">
                                                                            <input class="form-check-input"
                                                                                id="gateway-policy-{{ $gateway->id() }}-{{ $option }}"
                                                                                type="checkbox"
                                                                                name="gateways[{{ $gateway->id() }}][policy][{{ $option }}]"
                                                                                value="1" form="shop-payment-settings" @checked(old('gateways.' . $gateway->id() . '.policy.' . $option, $gatewayPolicy[$option] ?? false))>
                                                                            <label class="form-check-label"
                                                                                for="gateway-policy-{{ $gateway->id() }}-{{ $option }}">{{ __('shop::commerce.payments.policy.' . $option) }}</label>
                                                                        </div>
                                                                    @endif
                                                                @endforeach
                                                                @if ($gatewayPolicy['can_mix_wallet'] ?? false)
                                                                    <p class="form-text mb-0">
                                                                        {{ __('shop::commerce.payments.policy.wallet_products_help') }}</p>
                                                                @endif
                                                            @endif
                                                        </fieldset>
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                                <form class="card-footer" id="shop-payment-settings" data-ajax="true" data-redirect-url="response"
                                    method="post" action="{{ route('plugins.shop.admin.payments.settings.update') }}">
                                    @csrf
                                    <input type="hidden" name="catalogue_tab" value="payments">
                                    @include('partials.forms.sensitive-fields')
                                    <button class="btn btn-primary" type="submit">{{ __('shop::commerce.payments.webhook_setup.save_payment_settings') }}</button>
                                </form>
                        </section>
                    @endif
                @endif
                @if ($section !== 'payments')
                    </div>
                @endif
            </div>
        @endforeach
    </div>
    @push('scripts')
        <script type="module" src="{{ route('site.extensions.assets.show', ['id' => 'shop', 'asset' => 'admin.js']) }}"></script>
    @endpush
@endsection
