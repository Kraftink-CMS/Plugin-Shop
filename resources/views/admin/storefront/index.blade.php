@extends('admin.layouts.layout')
@section('title', __('shop::admin.storefront.settings'))
@section('content')
    <form data-ajax="true" method="post" action="{{ route('plugins.shop.admin.storefront.update') }}" class="vstack gap-4">
        @csrf
        <section class="card">
            <div class="card-header">
                <h2 class="card-title">{{ __('shop::admin.storefront.guests') }}</h2>
            </div>
            <div class="card-body">
                <input type="hidden" name="guests_enabled" value="0">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="guests-enabled" name="guests_enabled" value="1"
                        @checked(old('guests_enabled', $preferences['guests_enabled']))>
                    <label class="form-check-label" for="guests-enabled">{{ __('shop::admin.storefront.guests_enabled') }}</label>
                </div>
                <p class="form-text mb-0">{{ __('shop::admin.storefront.guests_help') }}</p>
                @unless ($mailEnabled)
                    <p class="alert alert-warning mt-3 mb-0">{{ __('shop::admin.storefront.mail_required') }}</p>
                @endunless
            </div>
        </section>
        <section class="card">
            <div class="card-header">
                <h2 class="card-title">{{ __('shop::admin.storefront.goal') }}</h2>
            </div>
            <div class="card-body">
                <input type="hidden" name="goal_enabled" value="0">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="goal-enabled" name="goal_enabled" value="1"
                        @checked(old('goal_enabled', $preferences['goal_enabled']))>
                    <label class="form-check-label" for="goal-enabled">{{ __('shop::admin.storefront.goal_enabled') }}</label>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="goal-title">{{ __('shop::admin.storefront.goal_title') }}</label>
                        <input class="form-control" id="goal-title" name="goal_title" maxlength="100"
                            value="{{ old('goal_title', $preferences['goal_title']) }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="goal-amount">{{ __('shop::admin.storefront.goal_amount') }}</label>
                        <input class="form-control" id="goal-amount" name="goal_amount" inputmode="decimal" required
                            value="{{ old('goal_amount', $preferences['goal_amount']) }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="goal-currency">{{ __('shop::admin.storefront.currency') }}</label>
                        <select class="form-select" id="goal-currency" name="goal_currency">
                            @foreach ($currencies as $currency)
                                <option value="{{ $currency }}" @selected(old('goal_currency', $preferences['goal_currency']) === $currency)>{{ $currency }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <p class="form-text mb-0">{{ __('shop::admin.storefront.goal_help') }}</p>
            </div>
        </section>
        <section class="card">
            <div class="card-header">
                <h2 class="card-title">{{ __('shop::admin.storefront.recent') }}</h2>
            </div>
            <div class="card-body">
                <input type="hidden" name="recent_enabled" value="0">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="recent-enabled" name="recent_enabled" value="1"
                        @checked(old('recent_enabled', $preferences['recent_enabled']))>
                    <label class="form-check-label"
                        for="recent-enabled">{{ __('shop::admin.storefront.recent_enabled') }}</label>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="recent-limit">{{ __('shop::admin.storefront.recent_limit') }}</label>
                    <input class="form-control" id="recent-limit" type="number" name="recent_limit" min="1"
                        max="20" required value="{{ old('recent_limit', $preferences['recent_limit']) }}">
                </div>
                <input type="hidden" name="recent_amounts" value="0">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="recent-amounts" name="recent_amounts" value="1"
                        @checked(old('recent_amounts', $preferences['recent_amounts']))>
                    <label class="form-check-label"
                        for="recent-amounts">{{ __('shop::admin.storefront.recent_amounts') }}</label>
                </div>
                <p class="form-text mb-0">{{ __('shop::admin.storefront.recent_help') }}</p>
            </div>
            <div class="card-footer"><button type="submit" class="btn btn-primary">{{ __('common.actions.save') }}</button></div>
        </section>
    </form>
@endsection
