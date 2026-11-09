@use('App\Kraft\SiteDates')
@use('App\Kraft\Wallet\Money')
@extends('admin.layouts.layout')
@section('title', __('shop::admin.reports.title'))
@section('actions')
    <a class="btn btn-outline-secondary" href="{{ route('plugins.shop.admin.catalog.index') }}">
        {{ __('shop::messages.title') }}</a><a class="btn btn-primary"
        href="{{ route('plugins.shop.admin.reports.export', $filters) }}">{{ __('shop::admin.reports.export') }}</a>
@endsection
@section('content')
    @php($dates = app(SiteDates::class))
    <section class="card">
        <div class="card-body">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label" for="sales_from">{{ __('shop::admin.reports.from', ['zone' => $dates->timezone()]) }}</label>
                    <input class="form-control" id="sales_from" name="from" type="date" value="{{ $filters['from'] }}"
                        required>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="sales_to">{{ __('shop::admin.reports.to', ['zone' => $dates->timezone()]) }}</label>
                    <input class="form-control" id="sales_to" name="to" type="date" value="{{ $filters['to'] }}"
                        required>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="sales_state">{{ __('shop::messages.labels.status') }}</label>
                    <select class="form-select" id="sales_state" name="state">
                        <option value="">{{ __('shop::admin.reports.all_states') }}</option>
                        @foreach (['pending', 'paid', 'refunded', 'cancelled', 'charged_back'] as $state)
                            <option value="{{ $state }}" @selected(($filters['state'] ?? '') === $state)>
                                {{ __('shop::messages.states.' . $state) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="sales_q">{{ __('shop::admin.reports.search') }}</label>
                    <input class="form-control" id="sales_q" name="q" value="{{ $filters['q'] ?? '' }}"
                        maxlength="100">
                </div>
                <div class="col-12">
                    <button class="btn btn-outline-primary" type="submit">{{ __('shop::admin.reports.filter') }}</button>
                </div>
            </form>
            <p class="text-body-secondary small mt-3 mb-0">{{ __('shop::admin.reports.period_help', ['zone' => $dates->timezone()]) }}</p>
        </div>
    </section>
    <section class="card">
        <div class="card-header">
            <h2 class="card-title">{{ __('shop::admin.reports.summary') }}</h2>
        </div>
        <div class="card-body p-0 table-responsive">
            <table class="table align-middle mb-0">
                <thead class="text-nowrap">
                    <tr>
                        <th>{{ __('shop::messages.labels.currency') }}</th>
                        <th>{{ __('shop::admin.reports.order_count') }}</th>
                        <th>{{ __('shop::admin.reports.active') }}</th>
                        <th>{{ __('shop::admin.reports.settled') }}</th>
                        <th>{{ __('shop::admin.reports.gross') }}</th>
                        <th>{{ __('shop::admin.reports.external') }}</th>
                        <th>{{ __('shop::admin.reports.refunded') }}</th>
                        <th>{{ __('shop::admin.reports.disputed') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($summary as $row)
                        <tr>
                            <th>{{ $row->currency }}</th>
                            <td>{{ $row->order_count }}</td>
                            <td>{{ $row->active_orders }}</td>
                            <td>{{ $row->settled_orders }}</td>
                            <td>{{ Money::format((int) $row->gross_minor) }} {{ $row->currency }}</td>
                            <td>{{ Money::format((int) $row->external_minor) }} {{ $row->currency }}
                            </td>
                            <td>{{ Money::format((int) $row->refunded_minor) }} {{ $row->currency }}
                            </td>
                            <td>{{ Money::format((int) $row->disputed_minor) }} {{ $row->currency }}
                            </td>
                        </tr>
                    @empty
                        <x-tables.empty :colspan="8" :message="__('shop::admin.reports.empty')" />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer small text-body-secondary">
            {{ __('shop::admin.reports.amounts_help') }}
            {{ __('shop::admin.reports.active_help') }}
        </div>
    </section>
    <div id="shop-admin-sales-orders" data-ajax-pagination data-table tabindex="-1" class="vstack gap-3">
        <section class="card">
            <div class="card-header">
                <h2 class="card-title">{{ __('shop::admin.reports.details') }}</h2>
            </div>
            <x-tables.controls :paginator="$orders" container-class="card-body" />
            <div class="card-body p-0 table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="text-nowrap">
                        <tr>
                            <th scope="col" data-table-sort="reference">{{ __('shop::messages.site.orders.order') }}</th>
                            <th scope="col" data-table-sort="buyer">{{ __('shop::admin.reports.buyer') }}</th>
                            <th scope="col" data-table-sort="status">{{ __('shop::messages.labels.status') }}</th>
                            <th scope="col" data-table-sort="created_at">{{ __('shop::admin.reports.date') }}</th>
                            <th>{{ __('shop::admin.reports.gross') }}</th>
                            <th>{{ __('shop::admin.reports.external') }}</th>
                            <th>{{ __('shop::admin.reports.refunded') }}</th>
                            <th>{{ __('shop::admin.reports.disputed') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($orders as $order)
                            <tr>
                                <td><a
                                        href="{{ route('plugins.shop.admin.orders.show', $order->reference) }}">{{ $order->reference }}</a><small
                                        class="d-block text-body-secondary">{{ $order->provider }}</small></td>
                                <td>{{ $order->buyer_username }}</td>
                                <td>{{ __('shop::messages.states.' . $order->status) }}</td>
                                <td class="text-nowrap">
                                    {{ $dates->format($order->paid_at ?: $order->created_at) }}</td>
                                <td class="text-nowrap">
                                    {{ Money::format($order->paid_at ? $order->total_minor + $order->giftcard_minor + ($order->wallet_minor ?? 0) : 0) }}
                                    {{ $order->currency }}</td>
                                <td class="text-nowrap">{{ Money::format((int) $order->external_minor) }}
                                    {{ $order->currency }}</td>
                                <td class="text-nowrap">{{ Money::format((int) $order->refunded_minor) }}
                                    {{ $order->currency }}</td>
                                <td class="text-nowrap">{{ Money::format((int) $order->disputed_minor) }}
                                    {{ $order->currency }}</td>
                            </tr>
                        @empty
                            <x-tables.empty :colspan="8" :message="__('shop::admin.reports.empty')" />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">
                {{ $orders->links() }}
            </div>
        </section>
    </div>
@endsection
