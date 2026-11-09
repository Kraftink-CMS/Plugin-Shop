@use('Illuminate\Support\Collection')
@use('KraftInk\Plugins\Shop\Models\Orders\OrderItem')
@use('Illuminate\Pagination\LengthAwarePaginator')
@use('KraftInk\Plugins\Shop\Models\Delivery\Delivery')
@use('App\Kraft\SiteDates')
@use('App\Kraft\Wallet\Money')
@use('KraftInk\Plugins\Shop\Services\Finance\WalletReturns')
@php
    /** @var Collection<int, OrderItem> $items */
    /** @var LengthAwarePaginator<int, Delivery> $deliveries */
@endphp
@extends('default.layouts.layout')
@section('title', __('shop::messages.site.orders.order') . ' #' . $order->id)
@section('content')
    <section>
        <header class="page-heading d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <h1 class="h2">{{ __('shop::messages.site.orders.order') }} #{{ $order->id }}</h1>
                <p class="small text-body-secondary mb-0 text-break">{{ $order->reference }}</p>
            </div>
            <span class="badge text-bg-secondary">{{ __('shop::messages.states.' . $order->status) }}</span>
        </header>
        <div class="card card-body p-4 mb-4">
            @if ($order->financial_hold)
                <p class="alert alert-warning">{{ __('shop::commerce.refunds.financial_hold') }}</p>
            @endif
            @if ($order->refunded_minor)
                <p>{{ __('shop::commerce.refunds.refunded_total') }} : {{ number_format($order->refunded_minor / 100, 2) }}
                    {{ $order->currency }}</p>
            @endif
            <ul class="list-group list-group-flush mb-4">
                @foreach ($items as $item)
                    <li class="list-group-item px-0 py-3">{{ $item->quantity }} × {{ $item->name }} ·
                        {{ number_format($item->unit_minor / 100, 2) }}
                        {{ $order->currency }}
                        @if ($item->recipient_id && $item->recipient_id !== $order->user_id)
                            <span
                                class="d-block small text-body-secondary">{{ __('shop::gifting.for_member', ['name' => $item->recipient?->username ?? __('shop::gifting.deleted_recipient')]) }}</span>
                        @endif
                        @if ($itemControls[$item->id]['canDownload'])
                            <a
                                href="{{ route($shopBuyerRoutePrefix . 'orders.downloads.show', [$order->reference, $item->id]) }}">{{ __('shop::messages.actions.download') }}</a>
                        @endif
                    </li>
                @endforeach
            </ul>
            @if ($codes->isNotEmpty())
                <h2 class="h5 mb-3">{{ __('shop::admin.products.fields.your_codes') }}</h2>
                @foreach ($codes as $code)
                    <article class="card card-body p-3 mb-3">
                        <strong>{{ $code['name'] }}</strong><code>{{ $code['code'] }}</code>
                    </article>
                @endforeach
            @endif
            <dl class="row mb-0 border-top pt-4">
                <dt class="col-6 col-md-8 fw-normal text-body-secondary">{{ __('shop::messages.site.orders.subtotal') }}
                </dt>
                <dd class="col-6 col-md-4 text-end">{{ number_format($order->subtotal_minor / 100, 2) }}
                    {{ $order->currency }}</dd>
                <dt class="col-6 col-md-8 fw-normal text-body-secondary">{{ __('shop::messages.site.orders.discount') }}
                </dt>
                <dd class="col-6 col-md-4 text-end">{{ number_format($order->discount_minor / 100, 2) }}
                    {{ $order->currency }}</dd>
                <dt class="col-6 col-md-8 fw-normal text-body-secondary">{{ __('shop::messages.site.cart.giftcard') }}</dt>
                <dd class="col-6 col-md-4 text-end">{{ number_format($order->giftcard_minor / 100, 2) }}
                    {{ $order->currency }}</dd>
                <dt class="col-6 col-md-8 fs-5 mt-2">{{ __('shop::messages.site.orders.total') }}</dt>
                <dd class="col-6 col-md-4 fs-5 fw-semibold text-end mt-2">
                    {{ number_format(($order->total_minor + $order->giftcard_minor + ($order->wallet_minor ?? 0)) / 100, 2) }}
                    {{ $order->currency }}</dd>
            </dl>
        </div>
        @if (($order->wallet_minor ?? 0) > 0)
            <dl class="row site-panel p-4 mb-4">
                <dt class="col-6 col-md-8 fw-normal text-body-secondary">{{ __('shop::commerce.wallet.payment_name') }}
                </dt>
                <dd class="col-6 col-md-4 text-end">{{ Money::format((int) $order->wallet_minor) }}
                    {{ $order->currency }}</dd>
                <dt class="col-6 col-md-8 fw-normal text-body-secondary">
                    {{ __('shop::commerce.documents.provider_amount') }}</dt>
                <dd class="col-6 col-md-4 text-end mb-0">{{ Money::format((int) $order->total_minor) }}
                    {{ $order->currency }}</dd>
            </dl>
        @endif
        @php
            $walletReturnPending = app(WalletReturns::class)->pendingForOrder($order->id);
        @endphp
        @if ($walletReturnPending > 0)
            <p class="alert alert-warning">
                {{ __('shop::commerce.wallet.return_pending', ['amount' => Money::format($walletReturnPending), 'currency' => $order->currency]) }}
            </p>
        @endif
        @if ($canResume)
            <form data-ajax="false" method="post"
                action="{{ route($shopBuyerRoutePrefix . 'orders.retry', $order->reference) }}">
                @csrf
                <button class="btn btn-primary">{{ __('shop::messages.site.orders.resume') }}</button>
            </form>
            <p>{{ __('shop::messages.site.orders.payment_help') }}</p>
        @endif
        <div id="shop-order-deliveries" data-ajax-pagination tabindex="-1" class="vstack gap-3">
            @if ($deliveries->total() > 0)
                <h2 class="h4 section-heading mt-4 mb-3">{{ __('shop::messages.site.orders.deliveries') }}</h2>
                <ul class="list-group mb-4">
                    @foreach ($deliveries as $delivery)
                        <li class="list-group-item py-3">{{ __('shop::messages.states.' . $delivery->status) }}</li>
                    @endforeach
                </ul>
                {{ $deliveries->links() }}
            @endif
        </div>
        <a class="btn btn-outline-secondary mb-4" href="{{ route($shopBuyerRoutePrefix . 'orders.index') }}"><i
                class="bi bi-arrow-left me-2" aria-hidden="true"></i>{{ __('shop::messages.site.orders.my_orders') }}</a>
    </section>
    @if ($order->payment_instructions && $order->status === 'pending')
        <section class="card card-body p-4 my-4">
            <h2 class="h4 mb-3">{{ __('shop::commerce.payments.manual.instructions') }}</h2>
            <p class="text-body-secondary">{{ __('shop::commerce.payments.manual.waiting') }}</p>
            <p>{!! nl2br(e($order->payment_instructions)) !!}</p>
            <p>{{ __('shop::messages.site.orders.order') }} : <strong>{{ $order->reference }}</strong></p>
            <p class="small text-body-secondary">{{ __('shop::commerce.payments.manual.reference_help') }}</p>
            <p>{{ __('shop::commerce.payments.manual.deadline', ['date' => app(SiteDates::class)->format($order->expires_at)]) }}
            </p>
        </section>
    @endif
    @include('shop::partials.pricing.summary')
    @include('shop::partials.receipts.list')
@endsection
