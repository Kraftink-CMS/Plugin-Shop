@use('Illuminate\Support\Collection')
@use('App\Models\Accounts\Role')
@use('KraftInk\Plugins\Shop\Models\Catalog\Product')
@php
    /** @var Collection<int, Role> $roles */
    /** @var Collection<int, Product> $products */
@endphp
@extends('admin.layouts.layout')
@section('title', $rule?->name ?? __('shop::admin.discounts.privileged.add'))
@section('content')
    <form data-ajax="true" @unless ($rule) data-redirect-url="response" @endunless class="card" method="post" action="{{ $rule ? route('plugins.shop.admin.privileged.update', $rule->id) : route('plugins.shop.admin.privileged.store') }}">
        @csrf
        @if ($rule)
            @method('PUT')
        @endif
        <div class="card-body">
            <p class="text-body-secondary">{{ __('shop::admin.discounts.privileged.help') }}</p>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="privileged-name">{{ __('shop::admin.discounts.privileged.name') }}</label>
                    <input class="form-control" id="privileged-name" name="name" value="{{ old('name', $rule?->name) }}" required maxlength="120">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="privileged-role">{{ __('shop::admin.discounts.privileged.role') }}</label>
                    <select class="form-select" id="privileged-role" name="role_id" required>
                        @foreach ($roles as $role)
                            <option value="{{ $role->id }}" @selected((int) old('role_id', $rule?->role_id) === $role->id)>{{ $role->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="privileged-type">{{ __('shop::admin.discounts.privileged.type') }}</label>
                    <select class="form-select" id="privileged-type" name="type">
                        @foreach (['percent', 'fixed'] as $type)
                            <option value="{{ $type }}" @selected(old('type', $rule?->type ?? 'percent') === $type)>{{ __('shop::admin.discounts.privileged.type_'.$type) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="privileged-value">{{ __('shop::admin.discounts.privileged.value') }}</label>
                    <input class="form-control" id="privileged-value" name="value" value="{{ old('value', $rule ? number_format($rule->amount / 100, 2, '.', '') : '') }}" inputmode="decimal" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="privileged-currency">{{ __('shop::admin.discounts.privileged.currency') }}</label>
                    <select class="form-select" id="privileged-currency" name="currency">
                        @foreach ($currencies as $currency)
                            <option value="{{ $currency }}" @selected(old('currency', $rule?->currency ?? 'EUR') === $currency)>{{ $currency }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="privileged-scope">{{ __('shop::admin.discounts.privileged.scope') }}</label>
                    <select class="form-select" id="privileged-scope" name="scope">
                        @foreach (['all', 'category', 'product'] as $scope)
                            <option value="{{ $scope }}" @selected(old('scope', $rule?->scope ?? 'all') === $scope)>{{ __('shop::admin.discounts.privileged.scope_'.$scope) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="privileged-category">{{ __('shop::admin.discounts.privileged.category') }}</label>
                    <select class="form-select" id="privileged-category" name="category_id">
                        <option value="">{{ __('shop::admin.discounts.privileged.none') }}</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected((int) old('category_id', $rule?->category_id) === $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="privileged-product">{{ __('shop::admin.discounts.privileged.product') }}</label>
                    <select class="form-select" id="privileged-product" name="product_id">
                        <option value="">{{ __('shop::admin.discounts.privileged.none') }}</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}" @selected((int) old('product_id', $rule?->product_id) === $product->id)>{{ $product->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="privileged-start">{{ __('shop::admin.discounts.privileged.starts') }}</label>
                    <x-forms.local-datetime name="starts_at" id="privileged-start" :value="$rule?->starts_at" />
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="privileged-end">{{ __('shop::admin.discounts.privileged.expires') }}</label>
                    <x-forms.local-datetime name="expires_at" id="privileged-end" :value="$rule?->expires_at" />
                </div>
                <div class="col-12">
                    <label class="form-check">
                        <input class="form-check-input" type="checkbox" name="combine_coupon" value="1" @checked(old('combine_coupon', $rule?->combine_coupon ?? false))>
                        {{ __('shop::admin.discounts.privileged.combine') }}
                    </label>
                    <p class="form-text">{{ __('shop::admin.discounts.privileged.combine_help') }}</p>
                    <label class="form-check">
                        <input class="form-check-input" type="checkbox" name="enabled" value="1" @checked(old('enabled', $rule?->enabled ?? true))>
                        {{ __('shop::admin.discounts.privileged.enabled') }}
                    </label>
                </div>
            </div>
        </div>
        <div class="card-footer d-flex flex-wrap gap-2">
            <button class="btn btn-primary" type="submit">{{ __('shop::admin.discounts.privileged.save') }}</button>
            <a class="btn btn-outline-secondary" href="{{ route('plugins.shop.admin.privileged.index') }}">{{ __('shop::admin.discounts.privileged.back') }}</a>
        </div>
    </form>
    @if ($rule)
        <section class="card mt-4">
            <div class="card-body">
                <h2 class="h5">{{ __('shop::admin.discounts.privileged.delete') }}</h2>
                <p>{{ __('shop::admin.discounts.privileged.delete_help') }}</p>
            </div>
            <form data-ajax="true" data-redirect-url="response" method="post" action="{{ route('plugins.shop.admin.privileged.destroy', $rule->id) }}">
                @csrf
                @method('DELETE')
                <div class="card-footer">
                    <button class="btn btn-outline-danger" type="submit">{{ __('shop::admin.discounts.privileged.delete') }}</button>
                </div>
            </form>
        </section>
    @endif
@endsection
