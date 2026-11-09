@if ($capabilities->extension('shop.account'))
    <section class="panel card card-body p-4 mb-4">
        <h2 class="mb-3">{{ __('shop::messages.title') }}</h2>
        <div class="d-flex flex-wrap gap-2">
            @if ($shopAccount->canViewOrders)
                <a class="btn btn-outline-primary"
                    href="{{ route('plugins.shop.public.orders.index') }}">{{ __('shop::messages.site.orders.my_orders') }}</a>
            @endif
            @if ($shopAccount->canViewGiftCards)
                <a class="btn btn-outline-primary"
                    href="{{ route('plugins.shop.public.giftcards.index') }}">{{ __('shop::commerce.giftcards.title') }}</a>
            @endif
            @if ($capabilities->extension('shop'))
                <a class="btn btn-outline-secondary"
                    href="{{ route('plugins.shop.public.catalog.index') }}">{{ __('shop::admin.products.fields.products') }}</a>
                <a class="btn btn-outline-secondary"
                    href="{{ route('plugins.shop.public.cart.index') }}">{{ __('shop::messages.site.cart.cart') }}</a>
            @endif
        </div>
    </section>
@endif
