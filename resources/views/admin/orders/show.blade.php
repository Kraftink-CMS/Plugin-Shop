@use('Illuminate\Support\Collection')
@use('KraftInk\Plugins\Shop\Models\Orders\OrderItem')
@use('Illuminate\Pagination\LengthAwarePaginator')
@use('KraftInk\Plugins\Shop\Models\Orders\PaymentEvent')
@use('KraftInk\Plugins\Shop\Models\Delivery\Delivery')
@use('KraftInk\Plugins\Shop\Models\Billing\Refund')
@use('KraftInk\Plugins\Shop\Models\Billing\Dispute')
@use('KraftInk\Plugins\Shop\Models\Billing\CreditRecovery')
@use('KraftInk\Plugins\Shop\Models\Billing\FinancialEvent')
@use('KraftInk\Plugins\Shop\Models\Billing\ManualRefund')
@use('App\Kraft\SiteDates')
@use('App\Kraft\Wallet\Money')
@use('App\Kraft\Wallet\Wallet')
@use('Illuminate\Support\Facades\Lang')
@use('Illuminate\Support\Str')
@use('KraftInk\Plugins\Shop\Services\Finance\WalletReturns')
@php
    /** @var Collection<int, OrderItem> $items */
    /** @var LengthAwarePaginator<int, PaymentEvent> $events */
    /** @var LengthAwarePaginator<int, Delivery> $deliveries */
    /** @var LengthAwarePaginator<int, Refund> $refunds */
    /** @var LengthAwarePaginator<int, Dispute> $disputes */
    /** @var LengthAwarePaginator<int, CreditRecovery> $creditRecoveries */
    /** @var LengthAwarePaginator<int, FinancialEvent> $financialEvents */
    /** @var LengthAwarePaginator<int, ManualRefund>|Collection<int, ManualRefund> $manualRefunds */
@endphp
@extends('admin.layouts.layout')
@section('title', __('shop::messages.site.orders.order') . ' #' . $order->id)
@section('content')
    <div class="d-flex flex-column gap-4">
        <section class="card">
            <div class="card-body">
                <p>{{ $order->reference }} · {{ $order->provider }} · {{ $order->provider_reference }}</p>
                <p>{{ __('shop::messages.states.' . $order->status) }} · {{ number_format(($order->total_minor + $order->giftcard_minor + ($order->wallet_minor ?? 0)) / 100, 2) }}
                    {{ $order->currency }}</p>
                <ul>
                    @foreach ($items as $item)
                        <li>{{ $item->quantity }} × {{ $item->name }}</li>
                    @endforeach
                </ul>
                <div id="shop-admin-order-events" data-ajax-pagination tabindex="-1" class="vstack gap-3">
                    <h2 class="h4">{{ __('shop::messages.site.orders.events') }}</h2>
                    <ul class="mb-0">
                        @foreach ($events as $event)
                            <li>{{ $event->provider }} · {{ $event->event_id }} · {{ app(SiteDates::class)->format($event->created_at) }}</li>
                        @endforeach
                    </ul>
                    {{ $events->links() }}
                </div>
            </div>
            @if ($order->status === 'pending' && $order->auto_expire && now()->gte($order->expires_at))
                <form class="card-footer" data-ajax="true" data-redirect-url="response" method="post"
                    action="{{ route('plugins.shop.admin.orders.cancel', $order->id) }}">
                    @csrf
                    <button class="btn btn-outline-danger" type="submit">{{ __('shop::messages.site.orders.release') }}</button>
                </form>
            @endif
        </section>
        <div id="shop-admin-order-deliveries" data-ajax-pagination data-table tabindex="-1" class="vstack gap-3">
            <section class="card">
                <div class="card-header">
                    <h2 class="card-title">{{ __('shop::integrations.delivery.queue_title') }}</h2>
                </div>
                <div class="card-body">
                    <x-tables.controls :paginator="$deliveries" class="mb-3" />
                    <p class="mb-1">{{ __('shop::integrations.delivery.automatic_help') }}</p>
                    <p class="mb-0 text-body-secondary">{{ __('shop::integrations.delivery.waiting_help') }}</p>
                </div>
                <div class="card-body p-0 table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="text-nowrap">
                            <tr>
                                <th scope="col" data-table-sort="command">{{ __('shop::integrations.delivery.command') }}</th>
                                <th scope="col" data-table-sort="player_name">{{ __('shop::integrations.delivery.player') }}</th>
                                <th scope="col" data-table-sort="status">{{ __('shop::integrations.delivery.status') }}</th>
                                <th scope="col" data-table-sort="created_at">{{ __('shop::integrations.delivery.dates') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($deliveries as $delivery)
                                @php
                                    $expired = $delivery->status === 'processing' && $delivery->lease_expires_at && now()->gte($delivery->lease_expires_at);
                                    $exhausted = $delivery->status === 'pending' && $delivery->attempts >= 10;
                                    $needsReview = $delivery->status === 'failed' || $expired || $exhausted;
                                @endphp
                                <tr>
                                    <td>
                                        <code class="text-break">{{ $delivery->command }}</code>
                                        <small class="d-block text-body-secondary">{{ __('shop::integrations.delivery.kinds.' . $delivery->kind) }}</small>
                                        @if ($delivery->last_error)
                                            <small class="d-block text-danger text-break">{{ __('shop::integrations.delivery.error') }} : {{ $delivery->last_error }}</small>
                                        @endif
                                    </td>
                                    <td>
                                        {{ $delivery->player_name ?: __('shop::integrations.delivery.unknown_player') }}
                                        @if ($delivery->requires_online)
                                            <small class="d-block text-body-secondary">{{ __('shop::integrations.delivery.requires_online') }}</small>
                                        @endif
                                    </td>
                                    <td>
                                        <span @class([
                                            'badge',
                                            'text-bg-success' => $delivery->status === 'delivered',
                                            'text-bg-danger' => $needsReview,
                                            'text-bg-secondary' => !$needsReview && $delivery->status !== 'delivered',
                                        ])>{{ Lang::has('shop::integrations.delivery.states.' . $delivery->status) ? __('shop::integrations.delivery.states.' . $delivery->status) : __('shop::integrations.delivery.unknown_state') }}</span>
                                        <small class="d-block text-body-secondary">{{ trans_choice('shop::integrations.delivery.attempts', $delivery->attempts, ['count' => $delivery->attempts]) }}</small>
                                        @if ($expired || $exhausted)
                                            <small class="d-block text-danger">{{ __($expired ? 'shop::integrations.delivery.expired' : 'shop::integrations.delivery.exhausted') }}</small>
                                        @elseif ($delivery->status === 'pending')
                                            <small class="d-block text-body-secondary">{{ __('admin.servers.deliveries.pending_' . $delivery->pending_reason) }}</small>
                                        @endif
                                    </td>
                                    <td>
                                        <small class="d-block">{{ __('shop::integrations.delivery.created_at') }} : {{ app(SiteDates::class)->format($delivery->created_at) }}</small>
                                        @if ($delivery->delivered_at)
                                            <small class="d-block">{{ __('shop::integrations.delivery.delivered_at') }} : {{ app(SiteDates::class)->format($delivery->delivered_at) }}</small>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <x-tables.empty :colspan="4" :message="__('shop::integrations.delivery.order_empty')" />
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="card-footer">
                    <a class="btn btn-outline-primary" href="{{ route('admin.servers.deliveries.index', ['source' => 'shop', 'search' => $order->reference]) }}">{{ __('shop::integrations.delivery.view_tracking') }}</a>
                    <div class="mt-3">
                        {{ $deliveries->links() }}
                    </div>
                </div>
            </section>
        </div>
        <section class="card card-body">
            <h2 class="h4">{{ __('shop::commerce.refunds.title') }}</h2>
            @if (($order->wallet_minor ?? 0) > 0)
                <p>{{ __('shop::commerce.wallet.payment_name') }} : {{ Money::format((int) $order->wallet_minor) }} {{ $order->currency }} ·
                    {{ __('shop::commerce.documents.provider_amount') }} : {{ Money::format((int) $order->total_minor) }} {{ $order->currency }}</p>
            @endif
            <p>{{ __('shop::commerce.refunds.refunded_total') }} : {{ number_format($order->refunded_minor / 100, 2) }}
                {{ $order->currency }}</p>
            @if ($order->financial_hold)
                <p class="alert alert-warning">{{ __('shop::commerce.refunds.financial_hold') }}</p>
            @endif
            @if ($order->financial_review)
                <p class="alert alert-warning">{{ __('shop::commerce.refunds.manual_review') }}</p>
            @endif
            @php
                $walletReturnPending = app(WalletReturns::class)->pendingForOrder($order->id);
            @endphp
            @if ($walletReturnPending > 0)
                <p class="alert alert-warning">{{ __('shop::commerce.wallet.return_pending', ['amount' => Money::format($walletReturnPending), 'currency' => $order->currency]) }}</p>
            @endif
            @if (auth()->user()->hasPermission('shop.refunds.manage'))
                @if (
                    $order->status === 'paid' &&
                        $order->pricing_review_reason === 'fulfillment_failed' &&
                        !$order->released_at &&
                        !$order->refunded_minor &&
                        !$order->financial_hold)
                    <form data-ajax="true" data-redirect-url="response" class="border rounded p-3 mb-3" method="post"
                        action="{{ route('plugins.shop.admin.orders.fulfillment.retry', $order->id) }}">
                        @csrf
                        <p class="form-text">{{ __('shop::commerce.upgrades.retry_help') }}</p>
                        <label class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="confirm_retry" value="1" required>
                            <span class="form-check-label">{{ __('shop::commerce.upgrades.retry_confirm') }}</span></label>
                        @include('partials.forms.sensitive-fields')
                        <button class="btn btn-outline-primary">{{ __('shop::commerce.upgrades.retry') }}</button>
                    </form>
                @endif
                @if ($order->paid_at && !$order->payment_reference && $order->total_minor > 0)
                    <form data-ajax="true" data-redirect-url="response" class="border rounded p-3 mb-3" method="post"
                        action="{{ route('plugins.shop.admin.orders.payments.refresh', $order->id) }}">
                        @csrf
                        @include('partials.forms.sensitive-fields')
                        <button class="btn btn-outline-primary">{{ __('shop::commerce.refunds.verify_payment') }}</button>
                    </form>
                @endif
                @if ($order->status === 'paid' && !$order->financial_hold)
                    <form data-ajax="true" data-redirect-url="response" class="border rounded p-3 mb-3" method="post"
                        action="{{ route('plugins.shop.admin.refunds.store', $order->id) }}">
                        @csrf
                        <input type="hidden" name="request_key" value="{{ Str::uuid() }}">
                        <p class="small text-body-secondary">{{ __('shop::commerce.refunds.refund_help') }}</p>
                        <div class="mb-3">
                            <label class="form-label" for="refund-amount">{{ __('shop::commerce.refunds.refund_amount') }}</label>
                            <input class="form-control" id="refund-amount" name="amount_minor" type="number" min="1"
                                max="{{ $order->total_minor + $order->giftcard_minor + ($order->wallet_minor ?? 0) - $order->refunded_minor }}" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="refund-reason">{{ __('shop::commerce.refunds.refund_reason') }}</label>
                            <input class="form-control" id="refund-reason" name="reason" maxlength="255" required>
                        </div>
                        <label class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="confirm_effects" value="1" required>
                            <span class="form-check-label">{{ __('shop::commerce.refunds.confirm_effects') }}</span></label>
                        @include('partials.forms.sensitive-fields')
                        <button class="btn btn-danger">{{ __('shop::commerce.refunds.refund_action') }}</button>
                    </form>
                @endif
            @endif
            <div id="shop-admin-order-refunds" data-ajax-pagination tabindex="-1" class="vstack gap-3">
                @foreach ($refunds as $refund)
                    <article class="border rounded p-3 mb-3">
                        <p>{{ number_format($refund->amount_minor / 100, 2) }} {{ $refund->currency }} ·
                            {{ __('shop::commerce.refunds.' . $refund->status) }} · {{ app(SiteDates::class)->format($refund->created_at) }}</p>
                        <p class="small">{{ __('shop::commerce.refunds.reason') }} : {{ $refund->reason }}</p>
                        @if ($refund->provider_reference)
                            <p class="small text-break">{{ $refund->provider_reference }}</p>
                        @endif
                        @if ((in_array($refund->status, ['pending', 'processing'], true) || ($refund->status === 'succeeded' && $walletReturnPending > 0 && ($refund->wallet_minor ?? 0) > 0)) && auth()->user()->hasPermission('shop.refunds.manage'))
                            <form data-ajax="true" data-redirect-url="response" class="mb-3" method="post" action="{{ route('plugins.shop.admin.refunds.refresh', $refund->id) }}">
                                @csrf
                                @include('partials.forms.sensitive-fields')
                                <button class="btn btn-outline-primary">{{ __('shop::commerce.refunds.refresh') }}</button>
                            </form>
                            @if (!$refund->provider_reference)
                                <details>
                                    <summary>{{ __('shop::commerce.refunds.reconcile') }}</summary>
                                    <form data-ajax="true" data-redirect-url="response" class="mt-3" method="post"
                                        action="{{ route('plugins.shop.admin.refunds.reconcile', $refund->id) }}">
                                        @csrf
                                        <p class="form-text">{{ __('shop::commerce.refunds.reconcile_help') }}</p>
                                        <div class="mb-3">
                                            <label class="form-label"
                                                for="provider-ref-{{ $refund->id }}">{{ __('shop::commerce.refunds.provider_reference') }}</label>
                                            <input class="form-control" id="provider-ref-{{ $refund->id }}"
                                                name="provider_reference" maxlength="180" required>
                                        </div>
                                        @include('partials.forms.sensitive-fields')
                                        <button class="btn btn-outline-primary">{{ __('shop::commerce.refunds.reconcile') }}</button>
                                    </form>
                                </details>
                            @endif
                        @endif
                    </article>
                @endforeach
                {{ $refunds->links() }}
            </div>
            <div id="shop-admin-order-disputes" data-ajax-pagination tabindex="-1" class="vstack gap-3">
                @foreach ($disputes as $dispute)
                    <article class="border rounded p-3 mb-3">
                        <p>{{ $dispute->provider_reference }} · {{ __('shop::commerce.refunds.' . $dispute->status) }} ·
                            {{ number_format($dispute->amount_minor / 100, 2) }} {{ $dispute->currency }}</p>
                        @if ($dispute->status === 'review' && auth()->user()->hasPermission('shop.refunds.manage'))
                            <p class="form-text">{{ __('shop::commerce.refunds.review_help') }}</p>
                            <form data-ajax="true" data-redirect-url="response" method="post" action="{{ route('plugins.shop.admin.disputes.review', $dispute->id) }}">
                                @csrf
                                <div class="mb-3">
                                    <label class="form-label"
                                        for="dispute-outcome-{{ $dispute->id }}">{{ __('shop::commerce.refunds.review_outcome') }}</label>
                                    <select class="form-select" id="dispute-outcome-{{ $dispute->id }}" name="outcome" required>
                                        <option value="">—</option>
                                        <option value="won">{{ __('shop::commerce.refunds.won') }}</option>
                                        <option value="lost">{{ __('shop::commerce.refunds.lost') }}</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label"
                                        for="dispute-note-{{ $dispute->id }}">{{ __('shop::commerce.refunds.review_note') }}</label>
                                    <textarea class="form-control" id="dispute-note-{{ $dispute->id }}" name="note" maxlength="1000" required></textarea>
                                </div>
                                <label class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" name="confirm_review" value="1" required>
                                    <span class="form-check-label">{{ __('shop::commerce.refunds.review_confirm') }}</span></label>
                                @include('partials.forms.sensitive-fields')
                                <button class="btn btn-outline-primary">{{ __('shop::commerce.refunds.review_action') }}</button>
                            </form>
                        @endif
                    </article>
                @endforeach
                {{ $disputes->links() }}
            </div>
            <div id="shop-admin-order-recoveries" data-ajax-pagination tabindex="-1" class="vstack gap-3">
                @foreach ($creditRecoveries as $recovery)
                    <article class="border rounded p-3 mb-3">
                        <h3 class="h5">{{ __('shop::commerce.wallet.recovery_title') }}</h3>
                        <p>{{ __('shop::commerce.wallet.recovery_progress', ['recovered' => Money::format($recovery->recovered_minor), 'total' => Money::format($recovery->amount_minor), 'currency' => $recovery->currency]) }}
                        </p>
                        @if (
                            $recovery->recovered_minor < $recovery->amount_minor &&
                                auth()->user()->hasPermission('wallet.manage') &&
                                auth()->user()->hasPermission('shop.refunds.manage'))
                            @php
                                $availableCredit = app(Wallet::class)->balance($recovery->user_id);
                            @endphp
                            <p class="small">
                                {{ __('shop::commerce.wallet.available_balance', ['amount' => Money::format($availableCredit), 'currency' => $recovery->currency]) }}
                            </p>
                            <p class="form-text">{{ __('shop::commerce.wallet.recovery_help') }}</p>
                            @if ($availableCredit > 0)
                                <form data-ajax="true" data-redirect-url="response" method="post" action="{{ route('plugins.shop.admin.credit_recoveries.store', $recovery->id) }}">
                                    @csrf
                                    <input type="hidden" name="request_key" value="{{ Str::uuid() }}">
                                    <div class="mb-3">
                                        <label class="form-label"
                                            for="recovery-amount-{{ $recovery->id }}">{{ __('shop::commerce.wallet.recovery_amount') }}</label>
                                        <input class="form-control" type="number" id="recovery-amount-{{ $recovery->id }}"
                                            name="amount_minor" min="1"
                                            max="{{ min($availableCredit, $recovery->amount_minor - $recovery->recovered_minor) }}"
                                            required>
                                    </div>
                                    <label class="form-check mb-3">
                                        <input class="form-check-input" type="checkbox" name="confirm_recovery" value="1"
                                            required>
                                        <span class="form-check-label">{{ __('shop::commerce.wallet.recovery_confirm') }}</span></label>
                                    @include('partials.forms.sensitive-fields')
                                    <button class="btn btn-danger">{{ __('shop::commerce.wallet.recovery_action') }}</button>
                                </form>
                            @endif
                        @endif
                    </article>
                @endforeach
                {{ $creditRecoveries->links() }}
            </div>
            <div id="shop-admin-order-financial-events" data-ajax-pagination tabindex="-1" class="vstack gap-3">
                @if ($financialEvents->total() > 0)
                    <details @if (request()->has('financial_events_page')) open @endif>
                        <summary>{{ __('shop::commerce.refunds.event_history') }}</summary>
                        <ul class="mt-3">
                            @foreach ($financialEvents as $event)
                                <li>{{ app(SiteDates::class)->format($event->created_at) }} · {{ $event->reference }} ·
                                    {{ __('shop::commerce.refunds.' . $event->status) }} ·
                                    {{ number_format($event->amount_minor / 100, 2) }}
                                    {{ $event->currency }}</li>
                            @endforeach
                        </ul>
                        {{ $financialEvents->links() }}
                    </details>
                @endif
            </div>
        </section>
        @if ($order->provider === 'manual')
            <section class="card card-body">
                <h2 class="h4">{{ __('shop::commerce.payments.manual.records') }}</h2>
                @if ($manualPayment)
                    <p>{{ __('shop::commerce.payments.manual.payment_record') }} ·
                        {{ Money::format($manualPayment->amount_minor) }} {{ $manualPayment->currency }} ·
                        {{ app(SiteDates::class)->format($manualPayment->confirmed_at) }}</p>
                    <p>{{ $manualPayment->reference }}</p>
                    <p>{{ $manualPayment->note }}</p>
                @endif
                <div id="shop-admin-order-manual-refunds" data-ajax-pagination tabindex="-1" class="vstack gap-3">
                    @foreach ($manualRefunds as $record)
                        <p>{{ __('shop::commerce.payments.manual.refund_record') }} · {{ Money::format($record->amount_minor) }}
                            {{ $record->currency }} · {{ app(SiteDates::class)->format($record->confirmed_at) }}</p>
                        <p>{{ $record->reference }}</p>
                        <p>{{ $record->note }}</p>
                    @endforeach
                    {{ $manualRefunds->links() }}
                </div>
                @if (auth()->user()->hasPermission('shop.payments.record'))
                    @if (!$manualPayment && !$order->paid_at && in_array($order->status, ['pending', 'cancelled'], true))
                        <form data-ajax="true" data-redirect-url="response" method="post" action="{{ route('plugins.shop.admin.payments.manual.store', $order->id) }}"
                            class="border rounded p-3 mt-3">
                            @csrf
                            <p>{{ __('shop::commerce.payments.manual.payment_help') }}</p>
                            <p class="small text-body-secondary">{{ __('shop::commerce.payments.manual.authority') }}</p>
                            @if (!$order->isPayable())
                                <p class="alert alert-warning">{{ __('shop::commerce.payments.manual.late_help') }}</p>
                            @endif
                            @include('shop::admin.payments.partials.manual_fields', [
                                'amountMinor' => $order->total_minor,
                                'formKey' => 'manual-payment-' . $order->id,
                            ])
                            <button class="btn btn-primary">{{ __('shop::commerce.payments.manual.confirm_payment') }}</button>
                        </form>
                    @endif
                    @if (auth()->user()->hasPermission('shop.refunds.manage'))
                        <div id="shop-admin-order-refunds-actions" data-ajax-pagination-related="shop-admin-order-refunds" class="vstack gap-3">
                            @foreach ($refunds->getCollection()->whereIn('status', ['pending', 'processing']) as $refund)
                                <form data-ajax="true" data-redirect-url="response" method="post" action="{{ route('plugins.shop.admin.refunds.manual.store', $refund->id) }}"
                                    class="border rounded p-3 mt-3">
                                    @csrf
                                    <p>{{ __('shop::commerce.payments.manual.refund_help') }}</p>
                                    @include('shop::admin.payments.partials.manual_fields', [
                                        'amountMinor' => $refund->external_minor,
                                        'formKey' => 'manual-refund-' . $refund->id,
                                    ])
                                    <button class="btn btn-outline-danger">{{ __('shop::commerce.payments.manual.confirm_refund') }}</button>
                                </form>
                            @endforeach
                        </div>
                    @endif
                @endif
            </section>
        @endif
        @if ($items->contains(fn($item) => $item->pricing_snapshot !== null))
            <p><a class="btn btn-outline-primary"
                    href="{{ route('plugins.shop.admin.orders.pricing.show', $order->id) }}">{{ __('shop::commerce.upgrades.calculation') }}</a></p>
        @endif
        @include('shop::partials.pricing.summary')
        @include('shop::partials.receipts.list')
    </div>
@endsection
