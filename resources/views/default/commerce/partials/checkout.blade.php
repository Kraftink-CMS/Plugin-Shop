<form data-ajax="false" method="post" action="{{ $checkoutAction }}" class="card card-body p-4 mb-4" data-payment-checkout
    data-payment-policies="{{ json_encode($checkoutControls->policies) }}"
    data-payment-wallet-eligible="{{ $checkoutControls->canUseWallet ? '1' : '0' }}"
    data-payment-wallet-currency="{{ $checkoutControls->walletCurrency }}"
    data-payment-giftcard-eligible="{{ $checkoutControls->canUseGiftcard ? '1' : '0' }}">
    @csrf
    <h2 class="h4 mb-4">{{ $checkoutTitle }}</h2>
    <input type="hidden" name="checkout_key" value="{{ $checkoutKey }}">
    @include('shop::default.commerce.partials.billing_fields')
    @if ($checkoutControls->canUseCoupon)
        <div class="mb-3">
            <label class="form-label" for="coupon">{{ __('shop::messages.site.cart.coupon') }}</label>
            <input class="form-control" id="coupon" name="coupon" value="{{ old('coupon') }}" maxlength="60">
        </div>
    @endif
    @if ($checkoutControls->canUseGiftcard)
        <fieldset class="mb-3" data-payment-giftcard @if (!$checkoutControls->canApplyGiftcard) hidden disabled @endif>
            <label class="form-label" for="giftcard">{{ __('shop::messages.site.cart.giftcard') }}</label>
            <input class="form-control" id="giftcard" name="giftcard" maxlength="100" autocomplete="off">
        </fieldset>
    @endif
    @if ($checkoutControls->methods)
        <div class="mb-3">
            <label class="form-label" for="provider">{{ __('shop::messages.site.cart.payment_method') }}</label>
            <select class="form-select" id="provider" name="provider" @required($checkoutControls->requiresProvider)>
                @foreach ($checkoutControls->methods as $method)
                    <option value="{{ $method['id'] }}" @disabled(!$method['canSelect']) @selected($method['selected'])>
                        {{ $method['label'] }}
                    </option>
                @endforeach
            </select>
        </div>
    @endif
    @foreach ($checkoutControls->conditions as $condition)
        <div class="small text-body-secondary mb-3" data-payment-conditions="{{ $condition['id'] }}"
            @if (!$condition['canShow']) hidden @endif>
            @foreach ($condition['messages'] as $message)
                <p class="{{ $loop->last ? 'mb-0' : 'mb-1' }}">{{ $message }}</p>
            @endforeach
        </div>
    @endforeach
    @if ($checkoutControls->canUseWallet)
        <fieldset class="mb-3" data-payment-wallet @if (!$checkoutControls->canApplyWallet) hidden disabled @endif>
            <label class="form-label"
                for="wallet-amount">{{ __('shop::commerce.payments.policy.wallet_amount') }}</label>
            <div class="input-group">
                <input class="form-control" id="wallet-amount" name="wallet_amount" type="text" inputmode="decimal"
                    maxlength="18" value="{{ old('wallet_amount') }}"
                    aria-describedby="wallet-available wallet-amount-help">
                <span class="input-group-text">{{ $checkoutControls->walletCurrency }}</span>
            </div>
            <p class="form-text mb-1" id="wallet-available">
                {{ __('shop::commerce.payments.policy.wallet_available', ['amount' => $checkoutControls->walletBalance, 'currency' => $checkoutControls->walletCurrency]) }}
            </p>
            <p class="form-text mb-0" id="wallet-amount-help">
                {{ __('shop::commerce.payments.policy.wallet_amount_help') }}</p>
        </fieldset>
    @endif
    @include('shop::default.commerce.partials.terms')
    @include('shop::default.cart.summary')
    <div>
        <button class="btn btn-primary w-100"
            @disabled(!$checkoutControls->canPay)>{{ $checkoutLabel ?? __('shop::messages.site.cart.pay') }}</button>
    </div>
    @if (!$checkoutControls->canPay && empty($productErrors) && empty($cartError))
        <p class="text-body-secondary mt-3 mb-0">{{ __('shop::commerce.giftcards.no_payment') }}</p>
    @endif
</form>
