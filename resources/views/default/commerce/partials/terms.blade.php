@use('KraftInk\Plugins\Shop\Services\Commerce\ReceiptService')
@php
    $termsUrl = app(ReceiptService::class)->termsUrl();
@endphp
<label class="form-check mb-3">
    <input class="form-check-input" type="checkbox" name="terms" value="1" required @checked(filter_var(old('terms'), FILTER_VALIDATE_BOOLEAN))>
    <span class="form-check-label">
        @if ($termsUrl)
            {{ __('shop::messages.site.cart.terms_prefix') }} <a href="{{ $termsUrl }}" target="_blank"
                rel="noopener noreferrer">{{ __('shop::messages.site.cart.terms_link') }}</a>.
        @else
            {{ __('shop::messages.site.cart.terms') }}
        @endif
    </span>
</label>
