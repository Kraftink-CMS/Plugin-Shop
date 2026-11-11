@php
    $id = 'gateway-' . $gateway->id() . '-' . $key;
    $name = 'gateways[' . $gateway->id() . '][' . $key . ']';
    $value = $fieldValue ?? $registry->setting($gateway->id(), $key, $field['default'] ?? '');
    $type = $field['type'] ?? 'secret';
@endphp
@if (isset($field['options']))
    <div class="mb-3">
        <label class="form-label" for="{{ $id }}">{{ __($field['label']) }}</label>
        <select class="form-select" id="{{ $id }}" name="{{ $name }}" @if (isset($fieldForm)) form="{{ $fieldForm }}" @endif>
            @foreach ($field['options'] as $option => $label)
                <option value="{{ $option }}" @selected((string) $option === $value)>{{ __($label) }}</option>
            @endforeach
        </select>
    </div>
@elseif ($type === 'boolean')
    <input type="hidden" name="{{ $name }}" value="0" @if (isset($fieldForm)) form="{{ $fieldForm }}" @endif>
    <div class="form-check mb-3">
        <input class="form-check-input" id="{{ $id }}" type="checkbox" name="{{ $name }}" value="1"
            @if (isset($fieldForm)) form="{{ $fieldForm }}" @endif
            @checked($value === '1')>
        <label class="form-check-label" for="{{ $id }}">{{ __($field['label']) }}</label>
    </div>
@else
    <div class="mb-3">
        <label class="form-label" for="{{ $id }}">{{ __($field['label']) }}</label>
        @if ($type === 'textarea')
            <textarea class="form-control" id="{{ $id }}" name="{{ $name }}" rows="4" maxlength="1024"
                @if (isset($fieldForm)) form="{{ $fieldForm }}" @endif>{{ $value }}</textarea>
        @else
            <input class="form-control" id="{{ $id }}" name="{{ $name }}"
                @if (isset($fieldForm)) form="{{ $fieldForm }}" @endif
                type="{{ $type === 'secret' ? 'password' : 'text' }}"
                autocomplete="{{ $type === 'secret' ? 'new-password' : 'off' }}"
                value="{{ $type === 'secret' ? '' : $value }}">
        @endif
        @if ($type === 'secret' && ($showSecretHelp ?? true))
            <div class="form-text">
                {{ __('shop::commerce.payments.settings.' . (($fieldHasValue ?? ($value !== '')) ? 'keep_secret' : 'new_secret')) }}
            </div>
        @endif
    </div>
@endif
