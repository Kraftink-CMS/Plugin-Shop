@php
    $provider = $gateway->id();
    $credentialAction = route('plugins.shop.admin.payments.credentials.update', $provider);
    $webhookAction = route('plugins.shop.admin.payments.webhook.update', $provider);
    $webhookError = 'gateways.' . $provider . '.' . $webhookField;
@endphp
<div class="mb-4" data-shop-webhook-setup>
    <section class="border rounded p-3 mb-3">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <h3 class="h6 mb-0">1. {{ __('shop::commerce.payments.webhook_setup.credentials') }}</h3>
            @if ($setup['saved'])
                <span class="badge text-bg-success">{{ __('shop::commerce.payments.webhook_setup.credentials_ready') }}</span>
            @endif
        </div>
        <form data-shop-payment-credentials data-ajax="true" data-redirect-url="response" method="post" action="{{ $credentialAction }}">
            @csrf
            <input type="hidden" name="catalogue_tab" value="payments">
            <div data-ajax-messages hidden></div>
            @foreach ($fields as $key => $field)
                @continue($key === $webhookField)
                @include('shop::admin.catalog.gateway-field', [
                    'fieldValue' => $key === 'sandbox' ? $setup['sandbox'] : null,
                    'fieldHasValue' => $setup['saved'] ?: null,
                ])
            @endforeach
            @include('partials.forms.sensitive-fields')
            <button class="btn {{ $setup['saved'] ? 'btn-outline-primary' : 'btn-primary' }}" type="submit">
                {{ __('shop::commerce.payments.webhook_setup.save_credentials') }}
            </button>
        </form>
    </section>

    <section class="border rounded p-3">
        <h3 class="h6">2. {{ __('shop::commerce.payments.webhook_setup.title') }}</h3>
        @if ($setup['saved'])
            <p class="text-body-secondary mb-0" data-shop-unsaved-credentials role="status" hidden>
                {{ __('shop::commerce.payments.webhook_setup.credentials_required') }}
            </p>
            <div data-shop-webhook-actions>
                @if ($setup['pending'] && $gatewayConfigured)
                    <p class="alert alert-info py-2">{{ __('shop::commerce.payments.webhook_setup.pending') }}</p>
                @endif
                <p class="text-body-secondary">{{ __('shop::commerce.payments.webhook_setup.' . $provider . '_help') }}</p>
                <form class="mb-3" data-ajax="true" data-redirect-url="response" method="post" action="{{ $webhookAction }}">
                    @csrf
                    <input type="hidden" name="catalogue_tab" value="payments">
                    <input type="hidden" name="mode" value="automatic">
                    <div data-ajax-messages hidden></div>
                    @include('partials.forms.sensitive-fields')
                    <button class="btn btn-primary" type="submit">{{ __('shop::commerce.payments.webhook_setup.submit') }}</button>
                </form>
                <details class="border rounded p-3" @if ($errors->has($webhookError)) open @endif>
                    <summary class="fw-semibold">{{ __('shop::commerce.payments.webhook_setup.manual') }}</summary>
                    <form class="mt-3" data-ajax="true" data-redirect-url="response" method="post" action="{{ $webhookAction }}">
                        @csrf
                        <input type="hidden" name="catalogue_tab" value="payments">
                        <input type="hidden" name="mode" value="manual">
                        <div data-ajax-messages hidden></div>
                        <p class="form-text">{{ __('shop::commerce.payments.webhook_setup.manual_help') }}</p>
                        @include('shop::admin.catalog.webhook-url')
                        @include('shop::admin.catalog.gateway-field', [
                            'key' => $webhookField,
                            'field' => $fields[$webhookField],
                            'showSecretHelp' => false,
                        ])
                        @include('partials.forms.sensitive-fields')
                        <button class="btn btn-outline-primary" type="submit">{{ __('shop::commerce.payments.webhook_setup.save_webhook') }}</button>
                    </form>
                </details>
            </div>
        @else
            <p class="text-body-secondary mb-0">{{ __('shop::commerce.payments.webhook_setup.credentials_required') }}</p>
        @endif
    </section>
</div>
