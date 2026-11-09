@extends('default.layouts.layout')
@section('title', __('shop::messages.guests.title'))
@section('content')
    <section class="mx-auto py-4" style="max-width: 42rem">
        <header class="page-heading mt-0">
            <h1 class="h2">{{ __('shop::messages.guests.title') }}</h1>
        </header>
        @if ($guest)
            <p>{{ __('shop::messages.guests.verified', ['email' => $guest->email_address]) }}</p>
            <div class="d-flex flex-wrap gap-2 mb-3">
                <a class="btn btn-primary"
                    href="{{ route('plugins.shop.public.cart.index') }}">{{ __('shop::messages.site.cart.checkout') }}</a>
                <a class="btn btn-outline-primary"
                    href="{{ route('plugins.shop.public.guests.orders.index') }}">{{ __('shop::messages.site.orders.my_orders') }}</a>
            </div>
            <form data-ajax="true" data-redirect-url="response" method="post"
                action="{{ route('plugins.shop.public.guests.logout') }}">
                @csrf
                <button class="btn btn-outline-secondary">{{ __('shop::messages.guests.logout') }}</button>
            </form>
        @else
            <p class="text-body-secondary mb-4">{{ __('shop::messages.guests.help') }}</p>
            <form data-ajax="true" data-redirect-url="response" method="post"
                action="{{ route('plugins.shop.public.guests.code') }}" class="card mb-4">
                @csrf
                <div class="card-body p-4">
                    <label class="form-label" for="guest-email">{{ __('shop::messages.guests.email') }}</label>
                    <input type="email" class="form-control" id="guest-email" name="email" value="{{ old('email') }}"
                        required maxlength="254" autocomplete="email">
                </div>
                <div class="card-footer px-4 py-3">
                    <button class="btn btn-primary">{{ __('shop::messages.guests.send_code') }}</button>
                </div>
            </form>
            @if ($hasChallenge)
                <form data-ajax="true" data-redirect-url="response" method="post"
                    action="{{ route('plugins.shop.public.guests.verify') }}" class="card">
                    @csrf
                    <div class="card-body p-4">
                        <label class="form-label" for="guest-code">{{ __('shop::messages.guests.code') }}</label>
                        <input class="form-control" id="guest-code" name="code" required pattern="[0-9]{8}"
                            maxlength="8" inputmode="numeric" autocomplete="one-time-code">
                        <p class="form-text">{{ __('shop::messages.guests.email_help') }}</p>
                    </div>
                    <div class="card-footer px-4 py-3">
                        <button class="btn btn-primary">{{ __('shop::messages.guests.verify') }}</button>
                    </div>
                </form>
            @endif
        @endif
    </section>
@endsection
