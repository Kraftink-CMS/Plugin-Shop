@use('Illuminate\Pagination\LengthAwarePaginator')
@use('KraftInk\Plugins\Shop\Models\Catalog\PrivilegedPrice')
@php
    /** @var LengthAwarePaginator<int, PrivilegedPrice> $rules */
@endphp
@extends('admin.layouts.layout')
@section('title', __('shop::admin.discounts.privileged.title'))
@section('description', __('shop::admin.discounts.privileged.help'))
@section('actions')
    <a class="btn btn-primary" href="{{ route('plugins.shop.admin.privileged.create') }}">{{ __('shop::admin.discounts.privileged.add') }}</a>
@endsection
@section('content')
    <div id="shop-admin-privileged-prices" data-ajax-pagination data-table tabindex="-1" class="vstack gap-3">
        <div class="card">
            <x-tables.controls :paginator="$rules" container-class="card-body" />
            <div class="card-body p-0 table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="text-nowrap">
                        <tr>
                            <th scope="col" data-table-sort="name">{{ __('shop::admin.discounts.privileged.name') }}</th>
                            <th scope="col" data-table-sort="role">{{ __('shop::admin.discounts.privileged.role') }}</th>
                            <th scope="col">{{ __('shop::admin.discounts.privileged.discount') }}</th>
                            <th scope="col" data-table-sort="scope">{{ __('shop::admin.discounts.privileged.scope') }}</th>
                            <th scope="col" data-table-sort="enabled">{{ __('shop::admin.discounts.privileged.enabled') }}</th>
                            <th scope="col" class="text-end">{{ __('shop::admin.discounts.privileged.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rules as $rule)
                            <tr>
                                <td>{{ $rule->name }}</td>
                                <td>{{ $rule->getAttribute('role_name') }}</td>
                                <td>{{ number_format($rule->amount / 100, 2) }} {{ $rule->type === 'percent' ? '%' : $rule->currency }}</td>
                                <td>{{ __('shop::admin.discounts.privileged.scope_'.$rule->scope) }}</td>
                                <td><span class="badge {{ $rule->enabled ? 'text-bg-success' : 'text-bg-secondary' }}">{{ __('shop::admin.discounts.privileged.'.($rule->enabled ? 'enabled' : 'disabled')) }}</span></td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-primary" href="{{ route('plugins.shop.admin.privileged.edit', $rule->id) }}">{{ __('shop::admin.discounts.privileged.edit') }}</a>
                                </td>
                            </tr>
                        @empty
                            <x-tables.empty :colspan="6" :message="__('shop::admin.discounts.privileged.empty')" :description="__('shop::admin.discounts.privileged.help')" :action="__('shop::admin.discounts.privileged.add')" :href="route('plugins.shop.admin.privileged.create')" />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">
                {{ $rules->links() }}
            </div>
        </div>
    </div>
@endsection
