@use('KraftInk\Plugins\Shop\Services\Commerce\BillingDetails')
@if (app(BillingDetails::class)->required())
    @php
        $businessCustomer = old('billing.type', 'individual') === 'business';
    @endphp
    <fieldset class="mb-4" data-shop-billing>
        <legend class="h5">{{ __('shop::commerce.documents.billing') }}</legend>
        <p class="text-body-secondary">{{ __('shop::commerce.documents.billing_help') }}</p>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="customer-type">{{ __('shop::commerce.documents.customer_type') }}</label>
                <select class="form-select" id="customer-type" name="billing[type]" required>
                    @foreach (['individual', 'business'] as $type)
                        <option value="{{ $type }}" @selected(old('billing.type', 'individual') === $type)>
                            {{ __('shop::commerce.documents.' . $type) }}</option>
                    @endforeach
                </select>
            </div>
            @foreach (['name' => 160, 'country' => 2] as $field => $limit)
                <div class="col-md-6">
                    <label class="form-label"
                        for="customer-{{ $field }}">{{ __('shop::commerce.documents.customer_' . $field) }}</label>
                    <input class="form-control" id="customer-{{ $field }}" name="billing[{{ $field }}]"
                        maxlength="{{ $limit }}" value="{{ old('billing.' . $field) }}" @required(in_array($field, ['name', 'country'], true))
                        @if ($field === 'country') pattern="[A-Z]{2}" @endif>
                </div>
            @endforeach
            <div class="col-12" data-shop-business @if (!$businessCustomer) hidden @endif>
                <div class="row g-3">
                    @foreach (['registration', 'vat_id'] as $field)
                        <div class="col-md-6">
                            <label class="form-label"
                                for="customer-{{ $field }}">{{ __('shop::commerce.documents.customer_' . $field) }}</label>
                            <input class="form-control" id="customer-{{ $field }}"
                                name="billing[{{ $field }}]" maxlength="100"
                                value="{{ old('billing.' . $field) }}" @disabled(!$businessCustomer)>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="col-12">
                <label class="form-label"
                    for="customer-address">{{ __('shop::commerce.documents.customer_address') }}</label>
                <textarea class="form-control" id="customer-address" name="billing[address]" rows="3" maxlength="1500" required>{{ old('billing.address') }}</textarea>
            </div>
        </div>
    </fieldset>
@endif
