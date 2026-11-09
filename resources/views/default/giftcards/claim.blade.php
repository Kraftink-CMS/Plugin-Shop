@use('App\Kraft\SiteDates')
@use('App\Kraft\Wallet\Money')
@extends('default.layouts.layout')
@section('title', __('shop::commerce.giftcards.claim'))
@section('content')
    <div class="row justify-content-center py-4">
        <div class="col-lg-7 col-xl-6">
            <section class="card">
                <div class="card-body p-4 p-lg-5">
                    <i class="bi bi-gift text-primary fs-2 d-block mb-3" aria-hidden="true"></i>
                    <h1 class="h2 mb-3">{{ __('shop::commerce.giftcards.claim') }}</h1>
                    <p class="text-body-secondary">{{ __('shop::commerce.giftcards.claim_help') }}</p>
                    <dl class="row border-top border-bottom py-3 mb-4">
                        <dt class="col-sm-6">{{ __('shop::commerce.giftcards.balance') }}</dt>
                        <dd class="col-sm-6">{{ Money::format($card->balance_minor) }}
                            {{ $card->currency }}</dd>
                        <dt class="col-sm-6">{{ __('shop::admin.discounts.coupons.expires_at') }}</dt>
                        <dd class="col-sm-6 mb-0">
                            {{ $card->expires_at ? app(SiteDates::class)->format($card->expires_at) : __('shop::messages.account.giftcards.no_expiry') }}
                        </dd>
                    </dl>
                    @if (!$card->available)
                        <p class="alert alert-warning">{{ __('shop::commerce.giftcards.unavailable') }}</p>
                    @else
                        @if ($card->assigned)
                            <p class="text-body-secondary">{{ __('shop::commerce.giftcards.claim_assigned') }}</p>
                        @endif
                        @auth
                            <form data-ajax="true" data-redirect-url="response" method="post"
                                action="{{ route('plugins.shop.public.giftcards.redeem', $token) }}">
                                @csrf
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" id="accept-giftcard" name="accept_card"
                                        value="1" required>
                                    <label class="form-check-label"
                                        for="accept-giftcard">{{ __('shop::messages.account.giftcards.accept') }}</label>
                                </div>
                                <button class="btn btn-primary"
                                    type="submit">{{ __('shop::commerce.giftcards.claim') }}</button>
                            </form>
                        @else
                            <p>{{ __('shop::commerce.giftcards.claim_login') }}</p>
                            <a class="btn btn-primary" href="{{ route('login') }}">{{ __('shop::messages.site.catalog.login') }}</a>
                        @endauth
                    @endif
                </div>
            </section>
        </div>
    </div>
@endsection
