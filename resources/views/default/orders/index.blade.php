@use('Illuminate\Pagination\LengthAwarePaginator')
@use('KraftInk\Plugins\Shop\Models\Orders\Order')
@use('App\Kraft\SiteDates')
@php
    /** @var LengthAwarePaginator<int, Order> $orders */
@endphp
@extends('default.layouts.layout')
@section('title', __('shop::messages.site.orders.my_orders'))
@section('content')
    <section>
        <header class="page-heading d-flex flex-wrap justify-content-between align-items-center gap-3">
            <h1 class="h2 mb-0">{{ __('shop::messages.site.orders.my_orders') }}</h1>
            @if (empty($guest))
                <a class="btn btn-outline-secondary" href="{{ route('plugins.shop.public.giftcards.index') }}"><i
                        class="bi bi-gift me-2" aria-hidden="true"></i>{{ __('shop::commerce.giftcards.title') }}</a>
            @else
                <a class="btn btn-outline-secondary"
                    href="{{ route('plugins.shop.public.guests.index') }}">{{ __('shop::messages.guests.title') }}</a>
            @endif
        </header>
        <div id="shop-orders" data-ajax-pagination tabindex="-1" class="vstack gap-3">
            @forelse($orders as $order)
                <article class="card card-body p-4 mb-3">
                    <h2 class="h5 mb-2"><a class="text-decoration-none"
                            href="{{ route($shopBuyerRoutePrefix . 'orders.show', $order->reference) }}">{{ __('shop::messages.site.orders.order') }}
                            #{{ $order->id }}</a></h2>
                    <p class="text-body-secondary mb-0">
                        {{ number_format(($order->total_minor + $order->giftcard_minor + ($order->wallet_minor ?? 0)) / 100, 2) }}
                        {{ $order->currency }} ·
                        {{ __('shop::messages.states.' . $order->status) }}</p>
                </article>
            @empty
                <div class="card card-body empty-state text-center py-5 mb-4">
                    <i class="bi bi-receipt fs-2 mb-3" aria-hidden="true"></i>
                    <p class="mb-0">{{ __('shop::messages.site.orders.no_orders') }}</p>
                </div>
            @endforelse
            {{ $orders->links() }}
        </div>
        @if (empty($guest))
            <h2 class="h4 section-heading mt-5 mb-3">{{ __('shop::messages.site.subscriptions.subscriptions') }}</h2>
        @endif
        <div id="shop-subscriptions" data-ajax-pagination tabindex="-1" class="vstack gap-3">
            @foreach ($subscriptions as $subscription)
                <article class="card card-body p-4 mb-3">
                    <p class="fw-semibold">{{ $subscription->product_name }} ·
                        {{ $subscriptionControls[$subscription->id]['statusLabel'] }}
                    </p>
                    <p class="small text-body-secondary">{{ __('shop::messages.site.subscriptions.paid_until') }}
                        {{ app(SiteDates::class)->format($subscription->paid_until) }}</p>
                    @if ($subscription->trial?->ends_at?->isFuture())
                        <p class="small text-body-secondary">
                            {{ __('shop::subscriptions.trial_ends', ['date' => app(SiteDates::class)->format($subscription->trial->ends_at)]) }}
                        </p>
                    @endif
                    @if ($subscriptionControls[$subscription->id]['canAddPaymentMethod'])
                        <form method="post" action="{{ route('plugins.shop.public.subscriptions.payment-method', $subscription->id) }}">
                            @csrf
                            <button class="btn btn-primary mb-3">{{ __('shop::subscriptions.add_payment_method') }}</button>
                        </form>
                    @endif
                    @if ($subscriptionControls[$subscription->id]['canResumePayment'])
                        <form method="post"
                            action="{{ route('plugins.shop.public.subscriptions.payment', $subscription->id) }}">
                            @csrf
                            <button class="btn btn-primary mb-3">{{ __('shop::subscriptions.resume') }}</button>
                        </form>
                    @endif
                    @if ($subscription->can_change_plan ?? false)
                        <p><a class="btn btn-outline-primary"
                                href="{{ route('plugins.shop.public.subscriptions.change', $subscription->id) }}">{{ __('shop::subscriptions.change') }}</a>
                        </p>
                    @endif
                    @if ($subscriptionControls[$subscription->id]['canCancel'])
                        <form data-ajax="true" data-redirect-url="response" method="post"
                            action="{{ route('plugins.shop.public.subscriptions.cancel', $subscription->id) }}"
                            data-confirm="{{ __('shop::messages.site.subscriptions.cancel_confirm') }}">
                            @csrf
                            <button
                                class="btn btn-primary">{{ __('shop::messages.site.subscriptions.cancel_subscription') }}</button>
                        </form>
                    @elseif($subscriptionControls[$subscription->id]['cancellationScheduled'])
                        <p>{{ __('shop::messages.site.subscriptions.cancellation_scheduled') }}</p>
                    @endif
                </article>
            @endforeach
            @if (empty($guest))
                {{ $subscriptions->links() }}
            @endif
        </div>
        @if (empty($guest))
            <section class="mt-5" aria-labelledby="shop-gifts-title">
                <h2 class="h4 section-heading mb-3" id="shop-gifts-title">{{ __('shop::gifting.received') }}</h2>
                <p class="text-body-secondary">{{ __('shop::gifting.received_help') }}</p>
                <div id="shop-gifts" data-ajax-pagination tabindex="-1" class="vstack gap-3">
                    @forelse ($gifts as $gift)
                        <article class="card card-body">
                            <h3 class="h5">{{ $gift['quantity'] }} × {{ $gift['name'] }}</h3>
                            <p class="small text-body-secondary">
                                {{ __('shop::gifting.received_at', ['date' => app(SiteDates::class)->format($gift['received_at'])]) }}
                            </p>
                            @if (!$gift['available'])
                                <p class="mb-0">{{ __('shop::gifting.unavailable') }}</p>
                            @else
                                @foreach ($gift['codes'] as $code)
                                    <code class="d-block user-select-all mb-2">{{ $code }}</code>
                                @endforeach
                                @if ($gift['can_download'])
                                    <a class="btn btn-outline-primary align-self-start"
                                        href="{{ route('plugins.shop.public.gifts.download', $gift['id']) }}">{{ __('shop::messages.actions.download') }}</a>
                                @endif
                            @endif
                        </article>
                    @empty
                        <div class="card card-body">
                            <p class="mb-0">{{ __('shop::gifting.received_empty') }}</p>
                        </div>
                    @endforelse
                    {{ $gifts->links() }}
                </div>
            </section>
        @endif
    </section>
@endsection
