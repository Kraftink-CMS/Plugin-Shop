@use('App\Kraft\Wallet\Money')
@use('Illuminate\Support\Str')
<input type="hidden" name="request_key" value="{{ Str::uuid() }}">
<input type="hidden" name="currency" value="{{ $order->currency }}">
<div class="mb-3">
    <label class="form-label" for="manual-amount-{{ $formKey }}">{{ __('shop::commerce.payments.manual.amount') }}
        ({{ $order->currency }})</label>
    <input class="form-control" type="text" name="amount" id="manual-amount-{{ $formKey }}"
        value="{{ Money::format($amountMinor) }}" readonly required>
</div>
<div class="mb-3">
    <label class="form-label" for="manual-reference-{{ $formKey }}">{{ __('shop::commerce.payments.manual.reference') }}</label>
    <input class="form-control" name="reference" id="manual-reference-{{ $formKey }}" maxlength="120" required>
</div>
<div class="mb-3">
    <label class="form-label" for="manual-note-{{ $formKey }}">{{ __('shop::commerce.payments.manual.note') }}</label>
    <textarea class="form-control" name="note" id="manual-note-{{ $formKey }}" maxlength="1000" required></textarea>
</div>
<label class="form-check mb-3">
    <input class="form-check-input" name="confirm_funds" type="checkbox" value="1" required>
    <span class="form-check-label">{{ __('shop::commerce.payments.manual.attest') }}</span></label>
@include('partials.forms.sensitive-fields')
