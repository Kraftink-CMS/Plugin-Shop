@use('Illuminate\Pagination\LengthAwarePaginator')
@use('KraftInk\Plugins\Shop\Models\Billing\Receipt')
@use('App\Kraft\Settings')
@php
    /** @var LengthAwarePaginator<int, Receipt> $receipts */
@endphp
<div id="shop-order-receipts" data-ajax-pagination tabindex="-1" class="vstack gap-3">
    @if ($receipts->total() > 0)
        <section class="my-4">
            <h2 class="h4">{{ __('shop::commerce.documents.title') }}</h2>
            <ul class="list-group">
                @foreach ($receipts as $receipt)
                    @php($document = ($receipt->snapshot ?? []))
                    <li class="list-group-item d-flex flex-wrap justify-content-between gap-2">
                        <span>{{ __('shop::commerce.documents.' . ($document['document_type'] ?? $receipt->kind)) }}
                            {{ $receipt->number }}</span><a
                            href="{{ route(request()->routeIs('plugins.shop.admin.*') ? 'plugins.shop.admin.receipts.download' : ($shopBuyerRoutePrefix ?? 'plugins.shop.public.').'receipts.download', $receipt->id) }}">{{ __('shop::commerce.documents.download') }}</a>
                        @if (request()->routeIs('plugins.shop.admin.*'))
                            <form data-ajax="true" data-redirect-url="response" method="post" action="{{ route('plugins.shop.admin.receipts.email', $receipt->id) }}"
                                class="w-100">
                                @csrf
                                <label class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="confirm_resend" value="1">
                                    <span class="form-check-label">{{ __('shop::commerce.documents.resend_confirm') }}</span>
                                </label>
                                <button class="btn btn-sm btn-outline-secondary"
                                    @disabled(!app(Settings::class)->get('mail.enabled', false))>{{ __('shop::commerce.documents.email_action') }}</button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
            {{ $receipts->links() }}
        </section>
    @elseif(request()->routeIs('plugins.shop.admin.*') && $order->paid_at && auth()->user()->hasPermission('shop.finance.configure'))
        <section class="card card-body my-3">
            <h2 class="h5">{{ __('shop::commerce.documents.historical') }}</h2>
            <p>{{ __('shop::commerce.documents.historical_notice') }}</p>
            <form data-ajax="true" data-redirect-url="response" method="post" action="{{ route('plugins.shop.admin.orders.receipts.store', $order->id) }}">
                @csrf
                <label class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="confirm_historical" value="1" required>
                    {{ __('shop::commerce.documents.historical_confirm') }}</label>
                @include('partials.forms.sensitive-fields')
                <button class="btn btn-outline-primary">{{ __('shop::commerce.documents.create') }}</button>
            </form>
        </section>
    @endif
</div>
