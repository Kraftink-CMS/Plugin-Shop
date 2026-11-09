@use('App\Kraft\SiteDates')
@use('App\Kraft\Wallet\Money')
@extends('default.layouts.layout')
@section('title', __('shop::commerce.giftcards.title'))
@section('content')
    <header class="page-heading d-flex flex-wrap justify-content-between align-items-center gap-3">
        <h1 class="h2 mb-0">{{ __('shop::commerce.giftcards.title') }}</h1>
        <a class="btn btn-outline-secondary"
            href="{{ route('plugins.shop.public.orders.index') }}">{{ __('shop::messages.site.orders.my_orders') }}</a>
    </header>
    <p class="text-body-secondary">{{ __('shop::messages.account.giftcards.intro') }}</p>
    <div id="shop-giftcards" data-ajax-pagination tabindex="-1" class="vstack gap-3">
        <div class="row g-4 mb-4">
            @forelse($cards as $card)
                <div class="col-lg-6">
                    <article class="card h-100">
                        <div class="card-header px-4 py-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
                            <h2 class="h6 mb-0">{{ __('shop::commerce.giftcards.giftcard') }} · •••• {{ $card->last_four }}</h2>
                            <span
                                class="badge text-bg-secondary">{{ !$card->enabled ? __('shop::messages.states.disabled') : ($card->expires_at && now()->gte($card->expires_at) ? __('shop::messages.states.expired') : ($card->balance_minor !== null && (int) $card->balance_minor === 0 ? __('shop::messages.account.giftcards.used') : ($card->claimed_at ? __('shop::commerce.giftcards.claimed_status') : __('shop::commerce.giftcards.awaiting')))) }}</span>
                        </div>
                        <div class="card-body p-4">
                            <dl class="row mb-3">
                                <dt class="col-sm-6">{{ __('shop::commerce.giftcards.balance') }}</dt>
                                <dd class="col-sm-6">
                                    {{ $card->balance_minor === null ? __('shop::messages.account.giftcards.private_balance') : Money::format($card->balance_minor) . ' ' . $card->currency }}
                                </dd>
                                @if ($card->face_minor !== null)
                                    <dt class="col-sm-6">{{ __('shop::commerce.giftcards.value') }}</dt>
                                    <dd class="col-sm-6">{{ Money::format($card->face_minor) }}
                                        {{ $card->currency }}</dd>
                                @endif
                                <dt class="col-sm-6">
                                    {{ __('shop::admin.discounts.coupons.expires_at') }}</dt>
                                <dd class="col-sm-6">
                                    {{ $card->expires_at ? app(SiteDates::class)->format($card->expires_at) : __('shop::messages.account.giftcards.no_expiry') }}
                                </dd>
                            </dl>
                            @if ($card->owned)
                                <p class="small text-body-secondary">{{ __('shop::messages.account.giftcards.owned_help') }}</p>
                            @endif
                            @if ($card->code)
                                <details>
                                    <summary>{{ __('shop::messages.account.giftcards.show_code') }}</summary>
                                    <div class="mt-3">
                                        <label class="form-label"
                                            for="giftcard-code-{{ $card->id }}">{{ __('shop::commerce.giftcards.code') }}</label>
                                        <div class="input-group">
                                            <input class="form-control font-monospace" id="giftcard-code-{{ $card->id }}"
                                                value="{{ $card->code }}" readonly autocomplete="off" spellcheck="false">
                                            <button class="btn btn-outline-secondary" type="button"
                                                data-copy="{{ $card->code }}"
                                                data-copied="{{ __('shop::messages.account.giftcards.copied') }}">{{ __('shop::commerce.giftcards.copy') }}</button>
                                        </div>
                                    </div>
                                </details>
                            @endif
                            @if ($card->claim_url)
                                <div class="mt-3">
                                    <label class="form-label"
                                        for="giftcard-link-{{ $card->id }}">{{ __('shop::commerce.giftcards.link') }}</label>
                                    <div class="input-group">
                                        <input class="form-control" id="giftcard-link-{{ $card->id }}"
                                            value="{{ $card->claim_url }}" readonly autocomplete="off" spellcheck="false">
                                        <button class="btn btn-outline-secondary" type="button"
                                            data-copy="{{ $card->claim_url }}"
                                            data-copied="{{ __('shop::messages.account.giftcards.copied') }}">{{ __('shop::commerce.giftcards.copy') }}</button>
                                    </div>
                                    <p class="form-text mb-0">{{ __('shop::commerce.giftcards.private') }}</p>
                                </div>
                            @elseif($card->buyer && !$card->owned)
                                <p class="small text-body-secondary mb-0">
                                    {{ $card->claimed_at ? __('shop::messages.account.giftcards.recipient_claimed') : __('shop::messages.account.giftcards.no_link') }}
                                </p>
                            @endif
                            @if ($card->mail_status)
                                <p class="small text-body-secondary mt-3 mb-0">
                                    {{ __('shop::commerce.giftcards.mail.states.' . $card->mail_status) }}</p>
                            @endif
                            @if ($card->can_email && !in_array($card->mail_status, ['pending', 'dispatching'], true))
                                <form data-ajax="true" data-redirect-url="response" method="post"
                                    action="{{ route('plugins.shop.public.giftcards.email', $card->id) }}" class="mt-3">
                                    @csrf
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="email_consent"
                                            id="card-mail-{{ $card->id }}" value="1" required>
                                        <label class="form-check-label small"
                                            for="card-mail-{{ $card->id }}">{{ __('shop::commerce.giftcards.mail.consent') }}</label>
                                    </div>
                                    @if ($card->mail_status)
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="checkbox" name="confirm_resend"
                                                id="card-resend-{{ $card->id }}" value="1" required>
                                            <label class="form-check-label small"
                                                for="card-resend-{{ $card->id }}">{{ __('shop::commerce.giftcards.mail.resend_help') }}</label>
                                        </div>
                                    @endif
                                    <button class="btn btn-outline-secondary btn-sm">{{ __('shop::commerce.giftcards.mail.send') }}</button>
                                </form>
                            @endif
                        </div>
                    </article>
                </div>
            @empty
                <div class="col-12">
                    <section class="card card-body empty-state text-center py-5">
                        <i class="bi bi-gift fs-2 mb-3" aria-hidden="true"></i>
                        <p>{{ __('shop::commerce.giftcards.empty') }}</p>
                        @if ($capabilities->extension('shop'))
                            <div>
                                <a class="btn btn-outline-primary"
                                    href="{{ route('plugins.shop.public.catalog.index') }}">{{ __('shop::messages.site.catalog.continue') }}</a>
                            </div>
                        @endif
                    </section>
                </div>
            @endforelse
        </div>
        {{ $cards->links() }}
    </div>
@endsection
