@use('Illuminate\Support\Collection')
@use('KraftInk\Plugins\Shop\Models\Orders\OrderItem')
@use('Illuminate\Pagination\LengthAwarePaginator')
@use('KraftInk\Plugins\Shop\Models\Pricing\UpgradeAllocation')
@use('KraftInk\Plugins\Shop\Services\Commerce\ReceiptService')
@php
    /** @var Collection<int, OrderItem> $items */
    /** @var OrderItem $item */
    /** @var LengthAwarePaginator<int, UpgradeAllocation> $sources */
@endphp
@extends('admin.layouts.layout')
@section('title', __('shop::commerce.upgrades.calculation'))
@section('actions')
    <a class="btn btn-outline-secondary" href="{{ route('plugins.shop.admin.orders.show', $order->reference) }}">
        {{ __('shop::messages.site.orders.order') }} #{{ $order->id }}</a>
@endsection
@section('content')
    @include('shop::partials.pricing.summary')
    <form method="get" class="card card-body">
        <label class="form-label" for="pricing-item">{{ __('shop::commerce.documents.product') }}</label>
        <div class="d-flex gap-2">
            <select class="form-select" name="item" id="pricing-item">
                @foreach ($items as $choice)
                    <option value="{{ $choice->id }}" @selected($choice->id === $item->id)>{{ $choice->name }}</option>
                @endforeach
            </select>
            <button class="btn btn-outline-primary">{{ __('shop::commerce.upgrades.view') }}</button>
        </div>
    </form>
    <div class="card card-body">
        <h2 class="h5">{{ $item->name }}</h2>
        <dl class="row mb-0">
            <dt class="col-sm-6">{{ __('shop::messages.site.orders.subtotal') }}</dt>
            <dd class="col-sm-6">
                {{ ReceiptService::money($item->unit_minor * $item->quantity, $order->currency) }}
            </dd>
            <dt class="col-sm-6">{{ __('shop::messages.site.orders.discount') }}</dt>
            <dd class="col-sm-6">
                {{ ReceiptService::money($item->promotion_minor, $order->currency) }}</dd>
            <dt class="col-sm-6">{{ __('shop::commerce.upgrades.target') }}</dt>
            <dd class="col-sm-6">
                {{ ReceiptService::money($snapshot['target_minor'], $order->currency) }}
            </dd>
            <dt class="col-sm-6">{{ __('shop::commerce.upgrades.credit') }}</dt>
            <dd class="col-sm-6">
                {{ ReceiptService::money($item->upgrade_credit_minor, $order->currency) }}
            </dd>
            <dt class="col-sm-6">{{ __('shop::commerce.upgrades.new_payment') }}</dt>
            <dd class="col-sm-6">
                {{ ReceiptService::money($item->paid_minor, $order->currency) }}</dd>
        </dl>
    </div>
    <h2 class="h4">{{ __('shop::commerce.upgrades.sources') }}</h2>
    <p class="text-body-secondary">{{ __('shop::commerce.upgrades.sources_help') }}</p>
    <div id="shop-admin-pricing-sources" data-ajax-pagination data-table tabindex="-1" class="vstack gap-3">
        <div class="card">
            <x-tables.controls :paginator="$sources" container-class="card-body" />
            <div class="card-body p-0 table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="text-nowrap">
                        <tr>
                            <th scope="col" data-table-sort="reference">{{ __('shop::messages.site.orders.order') }}</th>
                            <th scope="col" data-table-sort="name">{{ __('shop::commerce.documents.product') }}</th>
                            <th>{{ __('shop::commerce.upgrades.retained') }}</th>
                            <th>{{ __('shop::commerce.upgrades.used') }}</th>
                            <th>{{ __('shop::commerce.upgrades.available_now') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($sources as $source)
                            <tr>
                                <td><a
                                        href="{{ route('plugins.shop.admin.orders.show', $source->getAttribute('reference')) }}">#{{ $source->getAttribute('order_id') }}</a>
                                </td>
                                <td>{{ $source->getAttribute('name') }}</td>
                                <td>{{ ReceiptService::money($source->retained_minor, $source->getAttribute('currency')) }}
                                </td>
                                <td>{{ ReceiptService::money($source->credited_minor, $source->getAttribute('currency')) }}
                                </td>
                                <td @class([
                                    'text-danger' =>
                                        ($net[$source->source_item_id] ?? 0) < $source->credited_minor,
                                ])>
                                    {{ ReceiptService::money($net[$source->source_item_id] ?? 0, $source->getAttribute('currency')) }}
                                </td>
                            </tr>
                        @empty
                            <x-tables.empty :colspan="5" :message="__('shop::commerce.upgrades.no_sources')" />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">
                {{ $sources->links() }}
            </div>
        </div>
    </div>
@endsection
