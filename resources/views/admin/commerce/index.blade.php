@use('Illuminate\Pagination\LengthAwarePaginator')
@use('KraftInk\Plugins\Shop\Models\Billing\Receipt')
@use('Illuminate\Support\Collection')
@use('KraftInk\Plugins\Shop\Models\Billing\DocumentMessage')
@use('App\Kraft\SiteDates')
@use('KraftInk\Plugins\Shop\Services\Commerce\ReceiptService')
@php
    /** @var LengthAwarePaginator<int, Receipt> $documents */
    /** @var Collection<int, DocumentMessage> $messages */
@endphp
@extends('admin.layouts.layout')
@section('title', __('shop::commerce.documents.settings'))
@section('description', __('shop::commerce.documents.automation_help'))
@section('actions')
    <button class="btn btn-outline-primary" type="button" data-billing-preview
        data-preview-url="{{ route('plugins.shop.admin.commerce.preview') }}"
        data-loading="{{ __('shop::commerce.documents.preview_loading') }}"
        data-error="{{ __('shop::commerce.documents.preview_error') }}" data-bs-toggle="modal"
        data-bs-target="#billing-preview">
        {{ __('shop::commerce.documents.preview') }}
    </button>
@endsection
@section('content')
    <form data-ajax="true" class="card" method="post" action="{{ route('plugins.shop.admin.commerce.update') }}"
        data-billing-settings data-shop-errors='@json($errors->keys())'>
        @csrf
        <input type="hidden" name="active_panel" value="documents" data-billing-panel>
        <div class="card-header">
            <ul class="nav nav-tabs card-header-tabs m-0" role="tablist">
                @foreach (['documents' => 'title', 'identity' => 'identity', 'taxes' => 'taxes', 'sending' => 'sending'] as $panel => $label)
                    <li class="nav-item" role="presentation">
                        <button @class(['nav-link', 'active' => $loop->first]) id="billing-tab-{{ $panel }}" type="button"
                            data-bs-toggle="tab" data-bs-target="#billing-{{ $panel }}" role="tab"
                            aria-controls="billing-{{ $panel }}"
                            aria-selected="{{ $loop->first ? 'true' : 'false' }}">{{ __('shop::commerce.documents.' . $label) }}</button>
                    </li>
                @endforeach
            </ul>
        </div>
        <div class="card-body tab-content">
            <div class="tab-pane fade show active" id="billing-documents" role="tabpanel"
                aria-labelledby="billing-tab-documents">
                <div id="shop-admin-billing-documents" data-ajax-pagination data-table tabindex="-1" class="vstack gap-3">
                    <x-tables.controls :paginator="$documents" :form="false" />
                    <p class="text-body-secondary">{{ __('shop::commerce.documents.format_help') }}</p>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="text-nowrap">
                                <tr>
                                    <th scope="col" data-table-sort="number">{{ __('shop::commerce.documents.title') }}
                                    </th>
                                    <th scope="col" data-table-sort="issued_at">
                                        {{ __('shop::commerce.documents.issued_at') }}</th>
                                    <th scope="col" data-table-sort="gross_minor">
                                        {{ __('shop::commerce.documents.gross') }}</th>
                                    <th scope="col" data-table-sort="mail_status">
                                        {{ __('shop::commerce.documents.mail_status') }}</th>
                                    <th scope="col" data-table-sort="order_reference">
                                        {{ __('shop::commerce.documents.order_reference') }}</th>
                                    <th>{{ __('shop::commerce.documents.email_action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($documents as $document)
                                    @php($snapshot = $document->snapshot ?? [])
                                    <tr>
                                        <td><a href="{{ route('plugins.shop.admin.receipts.download', $document->id) }}">{{ __('shop::commerce.documents.' . ($snapshot['document_type'] ?? $document->kind)) }}
                                                {{ $document->number }}</a></td>
                                        <td>{{ app(SiteDates::class)->format($document->issued_at) }}</td>
                                        <td>{{ ReceiptService::money($document->gross_minor, $document->currency) }}
                                        </td>
                                        <td>{{ $document->getAttribute('mail_status') ? __('shop::commerce.documents.mail.states.' . $document->getAttribute('mail_status')) : __('shop::commerce.documents.no_mail') }}
                                        </td>
                                        <td><a
                                                href="{{ route('plugins.shop.admin.orders.show', $document->getAttribute('order_reference')) }}">{{ $document->getAttribute('order_reference') }}</a>
                                        </td>
                                        <td>
                                            @if (in_array($document->getAttribute('mail_status'), ['accepted', 'uncertain', 'cancelled'], true))
                                                <label class="form-check mb-2">
                                                    <input class="form-check-input"
                                                        form="billing-document-{{ $document->id }}" type="checkbox"
                                                        name="confirm_resend" value="1" required>
                                                    <span
                                                        class="form-check-label">{{ __('shop::commerce.documents.resend_confirm') }}</span>
                                                </label>
                                            @endif
                                            @if (!in_array($document->getAttribute('mail_status'), ['pending', 'dispatching'], true))
                                                <button class="btn btn-sm btn-outline-primary"
                                                    form="billing-document-{{ $document->id }}"
                                                    @disabled(!$mailEnabled)>{{ __('shop::commerce.documents.email_action') }}</button>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-body-secondary p-4">
                                            {{ __('shop::commerce.documents.empty') }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    {{ $documents->links(data: ['tableForm' => false]) }}
                </div>
            </div>
            <div class="tab-pane fade" id="billing-identity" role="tabpanel" aria-labelledby="billing-tab-identity">
                <div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="seller-type"
                                class="form-label">{{ __('shop::commerce.documents.seller_type') }}</label>
                            <select id="seller-type" name="seller_type" class="form-select" required>
                                @foreach (['individual', 'business'] as $type)
                                    <option value="{{ $type }}" @selected(old('seller_type', $configuration['seller_type']) === $type)>
                                        {{ __('shop::commerce.documents.' . $type) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="seller-country"
                                class="form-label">{{ __('shop::commerce.documents.seller_country') }}</label>
                            <input id="seller-country" name="seller_country" class="form-control" maxlength="2"
                                pattern="[A-Z]{2}" required
                                value="{{ old('seller_country', $configuration['seller_country'] ?: 'FR') }}">
                        </div>
                        <p class="form-text col-12 mb-0">{{ __('shop::commerce.documents.seller_type_help') }}</p>
                        @foreach (['seller_name' => 160, 'seller_email' => 254, 'seller_legal_form' => 100, 'seller_siren' => 9, 'seller_siret' => 14, 'seller_capital' => 100, 'seller_registration' => 160] as $field => $limit)
                            <div class="col-md-6" @if (!in_array($field, ['seller_name', 'seller_email'], true)) data-billing-business @endif
                                @if (in_array($field, ['seller_siren', 'seller_siret'], true)) data-billing-french @endif>
                                <label class="form-label"
                                    for="billing-{{ $field }}">{{ __('shop::commerce.documents.' . $field) }}</label>
                                <input class="form-control" id="billing-{{ $field }}" name="{{ $field }}"
                                    maxlength="{{ $limit }}" value="{{ old($field, $configuration[$field]) }}"
                                    @if ($field === 'seller_email') type="email" @endif>
                            </div>
                        @endforeach
                        <div class="col-12">
                            <label class="form-label"
                                for="seller-address">{{ __('shop::commerce.documents.seller_address') }}</label>
                            <textarea class="form-control" id="seller-address" name="seller_address" rows="3" maxlength="1500">{{ old('seller_address', $sellerAddress) }}</textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label"
                                for="commerce-terms-url">{{ __('shop::commerce.documents.terms_url') }}</label>
                            <input class="form-control" id="commerce-terms-url" name="terms_url" type="url"
                                maxlength="2000" value="{{ old('terms_url', $configuration['terms_url']) }}"
                                aria-describedby="commerce-terms-url-help">
                            <p class="form-text" id="commerce-terms-url-help">
                                {{ __('shop::commerce.documents.terms_url_help') }}</p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"
                                for="receipt-prefix">{{ __('shop::commerce.documents.prefix') }}</label>
                            @php($prefixYear = now()->utc()->year)
                            @php($invoiceSuffix = '-INV-' . $prefixYear . '-000001')
                            @php($receiptSuffix = '-' . $prefixYear . '-000001')
                            <div class="input-group">
                                <input class="form-control" id="receipt-prefix" name="number_prefix"
                                    value="{{ old('number_prefix', $prefix) }}" required maxlength="12"
                                    pattern="[A-Z0-9]{1,12}"
                                    aria-describedby="receipt-prefix-example receipt-prefix-help">
                                <span class="input-group-text" id="receipt-prefix-example" data-billing-number-suffix
                                    data-business-suffix="{{ $invoiceSuffix }}"
                                    data-individual-suffix="{{ $receiptSuffix }}"
                                    title="{{ __('shop::commerce.documents.prefix_example') }}">{{ old('seller_type', $configuration['seller_type']) === 'business' ? $invoiceSuffix : $receiptSuffix }}</span>
                            </div>
                            <p class="form-text" id="receipt-prefix-help">
                                {{ __('shop::commerce.documents.prefix_help') }}</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="tab-pane fade" id="billing-taxes" role="tabpanel" aria-labelledby="billing-tab-taxes">
                <div>
                    <div class="row g-3">
                        <div class="col-md-4" data-billing-business>
                            <label class="form-label"
                                for="tax-mode">{{ __('shop::commerce.documents.tax_mode') }}</label>
                            <select class="form-select" id="tax-mode" name="tax_mode" required>
                                @foreach (['none', 'exempt', 'standard'] as $mode)
                                    <option value="{{ $mode }}" @selected(old('tax_mode', $configuration['tax_mode']) === $mode)>
                                        {{ __('shop::commerce.documents.' . $mode) }}</option>
                                @endforeach
                            </select>
                        </div>
                        @foreach (['tax_label' => $taxLabel, 'tax_rate' => $rate, 'seller_tax_id' => $sellerTaxId] as $field => $value)
                            <div class="col-md-4" data-billing-tax>
                                <label class="form-label"
                                    for="billing-{{ $field }}">{{ __('shop::commerce.documents.' . ['tax_label' => 'tax_label', 'tax_rate' => 'rate', 'seller_tax_id' => 'tax_id'][$field]) }}</label>
                                <input class="form-control" id="billing-{{ $field }}" name="{{ $field }}"
                                    value="{{ old($field, $value) }}"
                                    maxlength="{{ $field === 'tax_label' ? 80 : 100 }}"
                                    @if ($field === 'tax_rate') inputmode="decimal" required @endif>
                            </div>
                        @endforeach
                        @foreach (['tax_notice' => 500, 'payment_terms' => 1500, 'legal_notice' => 2000] as $field => $limit)
                            <div class="col-12"
                                @if ($field === 'tax_notice') data-billing-exemption @elseif ($field === 'payment_terms') data-billing-business @endif>
                                <label class="form-label"
                                    for="billing-{{ $field }}">{{ __('shop::commerce.documents.' . $field) }}</label>
                                <textarea class="form-control" id="billing-{{ $field }}" name="{{ $field }}" rows="3"
                                    maxlength="{{ $limit }}">{{ old($field, $configuration[$field]) }}</textarea>
                                @if ($field !== 'legal_notice')
                                    <p class="form-text">{{ __('shop::commerce.documents.' . $field . '_help') }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="tab-pane fade" id="billing-sending" role="tabpanel" aria-labelledby="billing-tab-sending">
                @unless ($mailEnabled)
                    <p class="alert alert-warning">{{ __('shop::commerce.documents.email_site_disabled') }}</p>
                @endunless
                <div>
                    <label class="form-check">
                        <input type="hidden" name="email_enabled" value="0">
                        <input class="form-check-input" type="checkbox" name="email_enabled" value="1"
                            @checked(old('email_enabled', $emailEnabled))>
                        <span class="form-check-label">{{ __('shop::commerce.documents.email_enabled') }}</span>
                    </label>
                    <p class="form-text">{{ __('shop::commerce.documents.email_help') }}</p>
                    <div class="border-top pt-3 mt-2">
                        <h2 class="h5">{{ __('shop::commerce.documents.email_policy_title') }}</h2>
                        @foreach ($emailPolicies as $policy => $enabled)
                            <label class="form-check mb-2">
                                <input type="hidden" name="email_{{ $policy }}" value="0">
                                <input class="form-check-input" type="checkbox" name="email_{{ $policy }}"
                                    value="1" @checked(old('email_' . $policy, $enabled))>
                                <span
                                    class="form-check-label">{{ __('shop::commerce.documents.email_' . $policy) }}</span>
                            </label>
                        @endforeach
                        <p class="form-text">{{ __('shop::commerce.documents.email_policy_help') }}</p>
                    </div>
                </div>
                <div class="card mt-4">
                    <div class="card-header">
                        <h2 class="h5 mb-0">{{ __('shop::commerce.documents.email_history') }}</h2>
                    </div>
                    <div class="card-body p-0 table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="text-nowrap">
                                <tr>
                                    <th>{{ __('shop::commerce.documents.title') }}</th>
                                    <th>{{ __('shop::commerce.documents.mail_status') }}</th>
                                    <th>{{ __('shop::commerce.documents.email_mode') }}</th>
                                    <th>{{ __('shop::commerce.documents.email_requested_at') }}</th>
                                    <th>{{ __('shop::commerce.documents.email_action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($messages as $message)
                                    <tr>
                                        <td><a
                                                href="{{ route('plugins.shop.admin.receipts.download', $message->receipt_id) }}">{{ $message->getAttribute('number') }}</a>
                                        </td>
                                        <td>{{ __('shop::commerce.documents.mail.states.' . $message->status) }}</td>
                                        <td>{{ __('shop::commerce.documents.email_' . ($message->automatic ? 'automatic' : 'manual')) }}
                                        </td>
                                        <td>{{ app(SiteDates::class)->format($message->updated_at) }}</td>
                                        <td>
                                            @if (in_array($message->status, ['accepted', 'uncertain', 'cancelled'], true))
                                                <label class="form-check mb-2">
                                                    <input class="form-check-input"
                                                        form="billing-send-{{ $message->receipt_id }}" type="checkbox"
                                                        name="confirm_resend" value="1" required>
                                                    <span
                                                        class="form-check-label">{{ __('shop::commerce.documents.resend_confirm') }}</span>
                                                </label>
                                                <button class="btn btn-sm btn-outline-primary"
                                                    form="billing-send-{{ $message->receipt_id }}"
                                                    @disabled(!$mailEnabled)>{{ __('shop::commerce.documents.email_action') }}</button>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-body-secondary p-4">
                                            {{ __('shop::commerce.documents.email_empty') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-footer d-flex flex-wrap gap-2">
            <button class="btn btn-primary" type="submit">{{ __('shop::messages.actions.save') }}</button>
        </div>
    </form>
    <div id="shop-admin-billing-document-actions" data-ajax-pagination-related="shop-admin-billing-documents"
        class="vstack gap-3">
        @foreach ($documents as $document)
            <form data-ajax="true" data-redirect-url="response" id="billing-document-{{ $document->id }}"
                method="post" action="{{ route('plugins.shop.admin.receipts.email', $document->id) }}">
                @csrf
                <input type="hidden" name="billing_page" value="1">
            </form>
        @endforeach
    </div>
    @foreach ($messages as $message)
        <form data-ajax="true" data-redirect-url="response" id="billing-send-{{ $message->receipt_id }}" method="post"
            action="{{ route('plugins.shop.admin.receipts.email', $message->receipt_id) }}">
            @csrf
            <input type="hidden" name="billing_page" value="1">
        </form>
    @endforeach
    @push('scripts')
        <script type="module"
            src="{{ route('site.extensions.assets.show', ['id' => 'shop', 'asset' => 'admin.js']) }}">
        </script>
        <script type="module"
            src="{{ route('site.extensions.assets.show', ['id' => 'shop', 'asset' => 'billing.js']) }}">
        </script>
    @endpush
    <div class="modal fade" id="billing-preview" tabindex="-1" aria-labelledby="billing-preview-title"
        aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title fs-5" id="billing-preview-title">{{ __('shop::commerce.documents.preview') }}
                    </h2>
                    <button class="btn-close" type="button" data-bs-dismiss="modal"
                        aria-label="{{ __('admin.servers.close') }}"></button>
                </div>
                <div class="modal-body">
                    <p class="text-body-secondary" data-billing-preview-feedback role="status"></p>
                    <iframe class="w-100 border-0" style="height: 65vh"
                        title="{{ __('shop::commerce.documents.preview') }}" sandbox data-billing-preview-frame
                        hidden></iframe>
                </div>
            </div>
        </div>
    </div>
@endsection
