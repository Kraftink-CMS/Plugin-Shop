@php($webhookUrl = route('plugins.shop.webhooks.' . $gateway->id() . '.handle'))
<div class="mb-3">
    <label class="form-label" for="webhook-{{ $gateway->id() }}">{{ __('shop::commerce.payments.settings.webhook') }}</label>
    <div class="input-group">
        <input class="form-control" id="webhook-{{ $gateway->id() }}" value="{{ $webhookUrl }}" readonly>
        <button class="btn btn-outline-secondary" type="button" data-copy="{{ $webhookUrl }}"
            data-copied="{{ __('site.home.copied') }}">{{ __('common.actions.copy') }}</button>
    </div>
</div>
