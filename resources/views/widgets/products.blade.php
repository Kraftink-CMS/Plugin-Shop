<section class="card h-100">
    <div class="card-body">
        <h2 class="h5 card-title mb-3">{{ __('shop::widgets.products') }}</h2>
        <ul class="list-unstyled mb-0">
            @forelse ($products as $product)
                <li class="mb-2">
                    <a href="{{ route('plugins.shop.public.products.show', ['id' => $product->id, 'slug' => $product->slug]) }}">{{ $product->name }}</a>
                </li>
            @empty
                <li class="text-body-secondary">{{ __('shop::widgets.empty') }}</li>
            @endforelse
        </ul>
    </div>
</section>
