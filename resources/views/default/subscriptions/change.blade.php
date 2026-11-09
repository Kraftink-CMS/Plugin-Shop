@use('App\Kraft\Wallet\Money')
@use('App\Kraft\SiteDates')
@extends('default.layouts.layout')
@section('title', __('shop::subscriptions.change'))
@section('content')
    <section class="vstack gap-4">
        <header class="page-heading d-flex flex-wrap justify-content-between align-items-center gap-3">
            <h1 class="h2 mb-0">{{ __('shop::subscriptions.change') }}</h1>
            <a class="btn btn-outline-secondary" href="{{ route('plugins.shop.public.orders.index') }}">{{ __('common.actions.back') }}</a>
        </header>
        <div class="card card-body">
            <h2 class="h5">{{ $currentPlan['name'] }}</h2>
            <p class="mb-1">{{ Money::format($currentPlan['unit_minor']) }} {{ $subscription->order->currency }} · {{ __('shop::admin.products.billing.' . $currentPlan['billing_interval']) }}</p>
            <p class="text-body-secondary mb-0">{{ __('shop::subscriptions.current_until', ['date' => app(SiteDates::class)->format($subscription->paid_until)]) }}</p>
        </div>
        @if ($change)
            <form class="card" method="post" action="{{ route('plugins.shop.public.subscriptions.confirm', ['id' => $subscription->id, 'change' => $change->id]) }}">
                @csrf
                <div class="card-body">
                    <h2 class="h5">{{ $change->snapshot['name'] }}</h2>
                    <dl class="row mb-3">
                        <dt class="col-sm-6">{{ __('shop::subscriptions.due_now') }}</dt>
                        <dd class="col-sm-6">{{ Money::format($change->amount_minor) }} {{ $subscription->order->currency }}</dd>
                        <dt class="col-sm-6">{{ __('shop::subscriptions.effective') }}</dt>
                        <dd class="col-sm-6">{{ $change->amount_minor > 0 ? __('shop::subscriptions.after_payment') : app(SiteDates::class)->format($change->effective_at) }}</dd>
                        <dt class="col-sm-6">{{ __('shop::subscriptions.next_price') }}</dt>
                        <dd class="col-sm-6">{{ Money::format($change->snapshot['unit_minor']) }} {{ $subscription->order->currency }} · {{ __('shop::admin.products.billing.' . $change->snapshot['billing_interval']) }}</dd>
                    </dl>
                    <p>{{ __($change->amount_minor > 0 ? 'shop::subscriptions.proration_help' : 'shop::subscriptions.scheduled_help') }}</p>
                    @if (in_array($change->status, ['submitting', 'pending'], true) || $change->status === 'quoted' && $change->expires_at->isFuture())
                        <div class="form-check mb-3">
                            <input class="form-check-input" id="subscription_change_confirmed" type="checkbox" name="confirmed" value="1" required @checked(old('confirmed'))>
                            <label class="form-check-label" for="subscription_change_confirmed">{{ __('shop::subscriptions.confirm_help') }}</label>
                        </div>
                        @include('partials.forms.sensitive-fields')
                    @else
                        <p class="alert alert-info mb-0">{{ __('shop::subscriptions.quote_expired') }}</p>
                    @endif
                </div>
                <div class="card-footer d-flex flex-wrap gap-2">
                    @if (in_array($change->status, ['submitting', 'pending'], true) || $change->status === 'quoted' && $change->expires_at->isFuture())
                        <button type="submit" class="btn btn-primary">{{ __($change->status === 'pending' ? 'shop::subscriptions.resume' : ($change->status === 'submitting' ? 'shop::subscriptions.retry' : 'shop::subscriptions.confirm')) }}</button>
                    @endif
                    <a class="btn btn-outline-secondary" href="{{ route('plugins.shop.public.subscriptions.change', $subscription->id) }}">{{ __('shop::subscriptions.choose') }}</a>
                </div>
            </form>
        @else
            <form class="card" method="post" action="{{ route('plugins.shop.public.subscriptions.preview', $subscription->id) }}">
                @csrf
                <div class="card-body">
                    <label class="form-label" for="subscription_product">{{ __('shop::subscriptions.choose') }}</label>
                    <select class="form-select" id="subscription_product" name="product_id" required>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}" @selected((int) old('product_id') === $product->id)>{{ $product->name }} · {{ Money::format($product->price_minor) }} {{ $product->currency }}</option>
                        @endforeach
                    </select>
                    <p class="form-text mb-0">{{ __('shop::subscriptions.change_help') }}</p>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary" @disabled($products->isEmpty())>{{ __('shop::subscriptions.preview') }}</button>
                </div>
            </form>
        @endif
    </section>
@endsection
