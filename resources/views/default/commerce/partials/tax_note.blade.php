@use('App\Kraft\Settings')
@php
    $taxRate = (int) app(Settings::class)->get('shop.commerce.rate_bps', 0);
@endphp
@if ($taxRate > 0)
    <p class="small text-body-secondary">
        {{ __('shop::commerce.documents.included', ['label' => app(Settings::class)->get('shop.commerce.tax_label') ?: __('shop::commerce.documents.tax'), 'rate' => intdiv($taxRate, 100) . '.' . str_pad((string) ($taxRate % 100), 2, '0', STR_PAD_LEFT)]) }}
    </p>
@endif
