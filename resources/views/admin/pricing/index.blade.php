@use('Illuminate\Pagination\LengthAwarePaginator')
@use('KraftInk\Plugins\Shop\Models\Pricing\UpgradeGroup')
@use('KraftInk\Plugins\Shop\Models\Pricing\UpgradeState')
@use('App\Kraft\Currencies\CurrencyRegistry')
@php
    /** @var LengthAwarePaginator<int, UpgradeGroup> $groups */
    /** @var LengthAwarePaginator<int, UpgradeState> $states */
@endphp
@extends('admin.layouts.layout')
@section('title', __('shop::commerce.upgrades.title'))
@section('actions')
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#upgrade-new">
        {{ __('shop::commerce.upgrades.add') }}</button>
@endsection
@section('content')
    <p class="text-body-secondary">{{ __('shop::commerce.upgrades.help') }}</p>
    <div id="shop-admin-upgrade-groups" data-ajax-pagination data-table tabindex="-1" class="vstack gap-3">
        <div class="card">
            <x-tables.controls :paginator="$groups" container-class="card-body" />
            <div class="card-body p-0 table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="text-nowrap">
                        <tr>
                            <th scope="col" data-table-sort="name">{{ __('shop::messages.labels.name') }}</th>
                            <th scope="col" data-table-sort="currency">{{ __('shop::messages.labels.currency') }}</th>
                            <th scope="col" data-table-sort="strategy">{{ __('shop::commerce.upgrades.strategy') }}</th>
                            <th scope="col" data-table-sort="enabled">{{ __('shop::messages.labels.status') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($groups as $group)
                            <tr>
                                <td>{{ $group->name }}</td>
                                <td>{{ $group->currency }}</td>
                                <td>{{ ($strategies[$group->strategy] ?? null)?->label() ?? $group->strategy }}</td>
                                <td>{{ __('shop::messages.states.' . ($group->enabled ? 'enabled' : 'disabled')) }}</td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal"
                                        data-bs-target="#upgrade-{{ $group->id }}">{{ __('common.actions.edit') }}</button>
                                    <form data-ajax="true" data-redirect-url="response" class="d-inline" method="post"
                                        action="{{ route('plugins.shop.admin.pricing.destroy', $group->id) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button
                                            class="btn btn-sm btn-outline-danger">{{ __('shop::messages.actions.delete') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <x-tables.empty :colspan="5" :message="__('shop::commerce.upgrades.empty')" :action="__('shop::commerce.upgrades.add')" modal="upgrade-new" />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">
                {{ $groups->links() }}
            </div>
        </div>
        @foreach ($groups->getCollection()->concat([null]) as $group)
            <x-forms.modal :id="'upgrade-' . ($group?->id ?? 'new')" :title="$group ? __('common.actions.edit') . ' ' . $group->name : __('shop::commerce.upgrades.add')" :action="$group ? route('plugins.shop.admin.pricing.update', $group->id) : route('plugins.shop.admin.pricing.store')" :method="$group ? 'PUT' : 'POST'" :submit="__('shop::messages.actions.save')">
                <div class="mb-3">
                    <label class="form-label" for="upgrade-name-{{ $group?->id }}">{{ __('shop::messages.labels.name') }}</label>
                    <input class="form-control" id="upgrade-name-{{ $group?->id }}" name="name"
                        value="{{ $group?->name }}" required maxlength="120">
                </div>
                <div class="mb-3">
                    <label class="form-label"
                        for="upgrade-currency-{{ $group?->id }}">{{ __('shop::messages.labels.currency') }}</label>
                    @if ($group)
                        <input type="hidden" name="currency" value="{{ $group->currency }}">
                        <select class="form-select" id="upgrade-currency-{{ $group->id }}" disabled>
                            <option value="{{ $group->currency }}" selected>{{ $group->currency }}</option>
                        </select>
                    @else
                        <select class="form-select" name="currency" id="upgrade-currency-" data-searchable-select>
                            @foreach (app(CurrencyRegistry::class)->all() as $currency)
                                <option value="{{ $currency->code }}" @selected(old('currency', 'EUR') === $currency->code)>{{ $currency->code }}{{ $currency->label() === $currency->code ? '' : ' · ' . $currency->label() }}
                                </option>
                            @endforeach
                        </select>
                    @endif
                </div>
                <div class="mb-3">
                    <label class="form-label"
                        for="upgrade-strategy-{{ $group?->id }}">{{ __('shop::commerce.upgrades.strategy') }}</label>
                    <select class="form-select" name="strategy" id="upgrade-strategy-{{ $group?->id }}">
                        @foreach ($strategies as $strategy)
                            <option value="{{ $strategy->id() }}" @selected(($group?->strategy ?? 'net-paid') === $strategy->id())>{{ $strategy->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label"
                        for="upgrade-expired-{{ $group?->id }}">{{ __('shop::commerce.upgrades.expired_policy') }}</label>
                    <select class="form-select" name="expired_policy" id="upgrade-expired-{{ $group?->id }}">
                        @foreach (['reset', 'block'] as $policy)
                            <option value="{{ $policy }}" @selected(($group?->expired_policy ?? 'reset') === $policy)>
                                {{ __('shop::commerce.upgrades.expired_' . $policy) }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">
                        {{ __('shop::commerce.upgrades.revocation_help') }}
                    </div>
                </div>
                <input name="enabled" type="hidden" value="0">
                <div class="form-check">
                    <input class="form-check-input" id="upgrade-enabled-{{ $group?->id }}" name="enabled" type="checkbox"
                        value="1" @checked($group?->enabled ?? true)>
                    <label class="form-check-label"
                        for="upgrade-enabled-{{ $group?->id }}">{{ __('shop::messages.states.enabled') }}</label>
                </div>
            </x-forms.modal>
        @endforeach
    </div>
    <div id="shop-admin-upgrade-reviews" data-ajax-pagination data-table tabindex="-1" class="vstack gap-3">
        <h2 class="h4">{{ __('shop::commerce.upgrades.reviews') }}</h2>
        <div class="card">
            <x-tables.controls :paginator="$states" container-class="card-body" />
            <div class="card-body p-0 table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="text-nowrap">
                        <tr>
                            <th scope="col" data-table-sort="user">{{ __('shop::messages.labels.name') }}</th>
                            <th scope="col" data-table-sort="group">{{ __('shop::commerce.upgrades.title') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($states as $state)
                            <tr>
                                <td>{{ $state->getAttribute('username') }}</td>
                                <td>{{ $state->getAttribute('group_name') }}</td>
                                <td>
                                    @if (auth()->user()->hasPermission('shop.refunds.manage'))
                                        <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="modal"
                                            data-bs-target="#upgrade-review-{{ $state->id }}">{{ __('shop::commerce.upgrades.review_action') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <x-tables.empty :colspan="3" :message="__('shop::commerce.upgrades.no_reviews')" />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">
                {{ $states->links() }}
            </div>
        </div>
        @if (auth()->user()->hasPermission('shop.refunds.manage'))
            @foreach ($states as $state)
                <x-forms.modal :id="'upgrade-review-' . $state->id" :title="__('shop::commerce.upgrades.review_action') . ' · ' . $state->getAttribute('username')" :action="route('plugins.shop.admin.pricing.states.review', $state->id)" :submit="__('shop::messages.actions.save')">
                    <div class="mb-3">
                        <label class="form-label"
                            for="upgrade-decision-{{ $state->id }}">{{ __('shop::commerce.upgrades.decision') }}</label>
                        <select class="form-select" name="decision" id="upgrade-decision-{{ $state->id }}">
                            <option value="restore">{{ __('shop::commerce.upgrades.restore') }}</option>
                            <option value="reset">{{ __('shop::commerce.upgrades.reset') }}</option>
                        </select>
                        <div class="form-text">
                            {{ __('shop::commerce.upgrades.review_help') }}
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"
                            for="upgrade-note-{{ $state->id }}">{{ __('shop::commerce.upgrades.note') }}</label>
                        <textarea class="form-control" name="note" id="upgrade-note-{{ $state->id }}" required minlength="20"
                            maxlength="1000" rows="3"></textarea>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="confirm_review"
                            id="upgrade-confirm-{{ $state->id }}" value="1" required>
                        <label class="form-check-label"
                            for="upgrade-confirm-{{ $state->id }}">{{ __('shop::commerce.upgrades.confirm_review') }}</label>
                    </div>
                    @include('partials.forms.sensitive-fields')
                </x-forms.modal>
            @endforeach
        @endif
    </div>
@endsection
