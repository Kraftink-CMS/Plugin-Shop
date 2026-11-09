<?php

namespace KraftInk\Plugins\Shop\Http\Controllers;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Support\Collection;
use App\Kraft\Games\GameRegistry;
use App\Kraft\Site\Seo;
use App\Kraft\System\TableQuery;
use App\Kraft\Wallet\Money;
use App\Kraft\Wallet\Wallet;
use App\Models\Media\MediaAsset;
use App\Models\Servers\Server;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use KraftInk\Plugins\Shop\Contracts\Payments\CancelsSubscriptions;
use KraftInk\Plugins\Shop\Models\Billing\Receipt;
use KraftInk\Plugins\Shop\Models\Catalog\Product;
use KraftInk\Plugins\Shop\Models\Catalog\Category;
use KraftInk\Plugins\Shop\Models\Catalog\ProductCode;
use KraftInk\Plugins\Shop\Models\Catalog\ProductServer;
use KraftInk\Plugins\Shop\Models\Delivery\Delivery;
use KraftInk\Plugins\Shop\Models\Orders\Order;
use KraftInk\Plugins\Shop\Models\Orders\OrderItem;
use KraftInk\Plugins\Shop\Models\Orders\Subscription;
use KraftInk\Plugins\Shop\Models\Orders\SubscriptionPayment;
use KraftInk\Plugins\Shop\Services\Catalog\Catalog;
use KraftInk\Plugins\Shop\Services\Catalog\ProductOptions;
use KraftInk\Plugins\Shop\Services\Finance\RefundService;
use KraftInk\Plugins\Shop\Services\Orders\OrderService;
use KraftInk\Plugins\Shop\Services\Orders\SubscriptionService;
use KraftInk\Plugins\Shop\Services\Orders\SubscriptionPlans;
use KraftInk\Plugins\Shop\Services\Orders\SubscriptionTrials;
use KraftInk\Plugins\Shop\Services\Payments\GatewayRegistry;
use KraftInk\Plugins\Shop\Services\Payments\PayPalService;
use KraftInk\Plugins\Shop\Services\Payments\StripeService;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Kraft\Games\Contracts\ProvidesCommandIdentity;
use KraftInk\Plugins\Shop\Contracts\Payments\GuestCheckout;
use KraftInk\Plugins\Shop\Services\GuestAuth;

class ShopController
{
    public function index(Request $request): View
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'integer'],
        ]);
        $catalog = app(Catalog::class);
        $categories = $catalog->categories();
        $selected = !empty($data['category']) ? $categories->firstWhere('id', (int) $data['category']) : null;
        if (!empty($data['category']) && !$selected) {
            abort(404);
        }
        $query = Product::query()
            ->visibleTo($request->user())
            ->leftJoin('shop__categories', 'shop__categories.id', '=', 'shop__products.category_id')
            ->select('shop__products.*', 'shop__categories.name AS category');
        if ($selected) {
            $query->whereIn('shop__products.category_id', Category::descendantIds($selected->id));
        }
        if (($data['q'] ?? '') !== '') {
            $term = $catalog->searchText($data['q']);
            $query->where(function ($query) use ($term) {
                TableQuery::whereContains($query, ['shop__products.search_text'], $term);
                $query->orWhere(function ($query) use ($term) {
                    $query->whereNull('shop__products.search_text');
                    TableQuery::whereContains($query, ['shop__products.name'], $term);
                });
            });
        }
        $products = $query
            ->orderBy('shop__products.position')
            ->orderBy('shop__products.id')
            ->paginate(30)
            ->withQueryString();
        $configuredServers = ProductServer::query()
            ->whereIn('product_id', $products->pluck('id'))
            ->pluck('product_id')
            ->flip();
        $products->through(function ($product) use ($catalog, $categories, $configuredServers, $request) {
            $product = $catalog->localize($product);
            $product->category = $categories->firstWhere('id', $product->category_id)?->name;
            $product->has_server_choice = isset($configuredServers[$product->id]);
            $product->trial_eligible =
                (bool) ($product->billing_interval &&
                    $product->trial_days > 0 &&
                    (!$request->user() ||
                        app(SubscriptionTrials::class)->eligible($request->user(), $request->user(), $product)));

            return $product;
        });
        $images = MediaAsset::query()
            ->whereIn('id', $products->pluck('image_id')->filter()->push($selected?->image_id)->filter())
            ->get()
            ->keyBy('id');
        $categoryImage = $selected ? $images->get($selected->image_id) : null;

        return view('shop::default.catalog.index', [
            'products' => $products,
            'categories' => $categories,
            'selectedCategory' => $selected,
            'guestAccess' => app(GuestAuth::class)->enabled() ||
                app(GuestAuth::class)->guest($request->session()->get('shop.guest_token')) !== null,
            'images' => $images,
            'seo' => $selected ? [
                'excerpt' => $selected->description,
                'canonical' => route('plugins.shop.public.catalog.index', ['category' => $selected->id]),
                'og_image' => $categoryImage ? route('site.media.show', $categoryImage) : '',
            ] : [],
            'seoVariables' => [
                'category' => $selected?->name ?? __('shop::messages.title'),
                'category_description' => $selected?->description,
                'products_count' => $products->total(),
            ],
        ]);
    }

    public function product(Request $request, int $id, ?string $slug = null): RedirectResponse|View
    {
        $product = Product::query()->visibleTo($request->user())->where('shop__products.id', $id)->first();
        if ($product === null) {
            abort(404);
        }
        if ($product->slug && $slug !== $product->slug) {
            return redirect()->route('plugins.shop.public.products.show', [$id, $product->slug], 301);
        }
        $product = app(Catalog::class)->localize($product);
        $cartQuantity = (int) $request->session()->get('shop.cart.'.$id, 0);
        $draft = $request->session()->get('shop.subscription');
        $editingCart = !$product->billing_interval && $request->boolean('edit') && $cartQuantity > 0;
        $editingSubscription =
            (bool) ($product->billing_interval && $request->boolean('edit') && $draft && $draft['product_id'] === $id);
        $options = $editingSubscription
            ? $draft['options']
            : ($editingCart
                ? $request->session()->get('shop.options.'.$id, [])
                : []);
        $trialEligible = (bool) ($product->billing_interval && $product->trial_days > 0);
        if ($trialEligible && $request->user()) {
            try {
                $recipient = app(ProductOptions::class)->beneficiary($request->user(), $options);
                $trialEligible = app(SubscriptionTrials::class)->eligible($request->user(), $recipient, $product);
            } catch (ValidationException) {
                $trialEligible = false;
            }
        }
        $servers = $product
            ->servers()
            ->where('servers.game', app(GameRegistry::class)->activeId())
            ->select('servers.id', 'servers.name', 'servers.translations')
            ->orderBy('servers.name')
            ->get()
            ->map(fn (Server $server) => (object) ['id' => $server->id, 'name' => $server->displayText('name')]);
        $image = $product->image_id ? MediaAsset::query()->find($product->image_id) : null;

        return view('shop::default.products.show', [
            'product' => $product,
            'serverChoices' => $servers,
            'options' => $options,
            'trialEligible' => $trialEligible,
            'editingCart' => $editingCart,
            'editingSubscription' => $editingSubscription,
            'addQuantityLimit' => $product->billing_interval ? 1 : max(0, $product->quantityLimit() - $cartQuantity),
            'quantity' => $editingCart ? $cartQuantity : 1,
            'image' => $image,
            'seo' => [
                'excerpt' => $product->description,
                'canonical' => route('plugins.shop.public.products.show', ['id' => $product->id, 'slug' => $product->slug]),
                'og_image' => $image ? route('site.media.show', $image) : '',
                'robots' => $product->required_permission ? Seo::NO_INDEX : '',
            ],
            'seoVariables' => [
                'product' => $product->name,
                'product_description' => $product->description,
                'price' => Money::format($product->price_minor),
                'currency' => $product->currency,
            ],
        ]);
    }

    public function cart(Request $request, GatewayRegistry $gateways): View
    {
        $cart = $request->session()->get('shop.cart', []);
        /** @var Collection<int, Product> $products */
        $products = Product::query()
            ->visibleTo($request->user())
            ->whereNull('billing_interval')
            ->whereIn('shop__products.id', array_keys($cart))
            ->get()
            ->map(fn ($product) => app(Catalog::class)->localize($product));
        $cart = array_intersect_key($cart, array_flip($products->pluck('id')->all()));
        $request->session()->put('shop.cart', $cart);
        $options = array_intersect_key($request->session()->get('shop.options', []), $cart);
        foreach ($products as $product) {
            if (!$product->custom_price) {
                $options[$product->id]['price_minor'] = (int) $product->price_minor;
            }
        }
        $request->session()->put('shop.options', $options);
        $prices = $products
            ->mapWithKeys(
                fn ($product) => [
                    $product->id => (int) ($options[$product->id]['price_minor'] ?? $product->price_minor),
                ],
            )
            ->all();
        foreach ($products as $product) {
            $product->cart_price_minor = $prices[$product->id];
        }
        $configuredServers = ProductServer::query()
            ->whereIn('product_id', $products->pluck('id'))
            ->pluck('product_id')
            ->flip();
        foreach ($products as $product) {
            $product->has_server_choice = isset($configuredServers[$product->id]);
        }
        $cartError = null;
        foreach ($products as $product) {
            if ((int) $cart[$product->id] > $product->quantityLimit()) {
                $cartError ??= __('validation.max.numeric', [
                    'attribute' => __('shop::messages.site.cart.quantity').' ('.$product->name.')',
                    'max' => $product->quantityLimit(),
                ]);
            }
        }
        $acceptsGiftcard = !$products->contains(
            fn ($product) => $product->kind === 'giftcard' || $product->wallet_credit_minor > 0,
        );
        $acceptsCoupon = !$products->contains(fn ($product) => $product->wallet_credit_minor > 0);
        $available = collect($gateways->available())->reject(
            fn ($gateway) => !$acceptsGiftcard &&
                array_intersect(['internal_balance', 'zero_total'], $gateway->capabilities()),
        );
        $available = $available->filter(
            fn ($gateway) => $products->every(
                fn ($product) => $gateways->supportsCurrency($gateway->id(), $product->currency),
            ),
        );
        $currency = $products->pluck('currency')->unique()->count() === 1 ? $products->first()->currency : '';
        $wallet = app(Wallet::class);
        $walletBalance = $request->user() ? $wallet->balance($request->user()) : 0;
        $games = app(GameRegistry::class);

        return view('shop::default.cart.index', [
            'products' => $products,
            'cart' => $cart,
            'options' => $options,
            'cartError' => $cartError,
            'serverNames' => Server::query()
                ->whereIn('id', collect($options)->pluck('server_id')->filter())
                ->get()
                ->mapWithKeys(fn (Server $server) => [$server->id => $server->displayText('name')]),
            'checkoutKey' => (string) Str::uuid(),
            'gateways' => $available,
            'acceptsGiftcard' => $acceptsGiftcard,
            'acceptsCoupon' => $acceptsCoupon,
            'walletBalance' => Money::format($walletBalance),
            'walletCurrency' => $wallet->currency(),
            'canUseWallet' => $acceptsGiftcard && $walletBalance > 0 && $currency === $wallet->currency(),
            'guestEnabled' => app(GuestAuth::class)->enabled(),
            'guestIdentityUsesId' => $games->find($games->activeId()) instanceof ProvidesCommandIdentity,
            'guestBuyer' => app(GuestAuth::class)->guest($request->session()->get('shop.guest_token')),
            'guestGateways' => $available->filter(fn ($gateway) => $gateway instanceof GuestCheckout),
            'guestEligible' => $products->every(
                fn ($product) => !$product->role_id &&
                    !$product->wallet_credit_minor &&
                    !$product->upgrade_group &&
                    $product->kind !== 'giftcard',
            ),
        ]);
    }

    public function updateCart(Request $request, int $id): RedirectResponse
    {
        $product = Product::query()
            ->visibleTo($request->user())
            ->whereNull('billing_interval')
            ->where('shop__products.id', $id)
            ->first();
        if ($product === null) {
            abort(404);
        }
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:0', 'max:'.$product->quantityLimit()],
            'intent' => ['sometimes', Rule::in(['add', 'set'])],
        ]);
        $cart = $request->session()->get('shop.cart', []);
        $options = $request->session()->get('shop.options', []);
        if ($data['quantity'] === 0 || $data['quantity'] === '0') {
            unset($cart[$id]);
            unset($options[$id]);
        } else {
            if (!isset($cart[$id]) && count($cart) >= 50) {
                throw new HttpException(422);
            }
            $adding = ($data['intent'] ?? 'set') === 'add';
            $quantity = (int) $data['quantity'] + ($adding ? (int) ($cart[$id] ?? 0) : 0);
            if ($quantity > $product->quantityLimit()) {
                throw ValidationException::withMessages([
                    'quantity' => __('shop::messages.products.options.cart_limit'),
                ]);
            }
            $newOptions = app(ProductOptions::class)->fromRequest(
                $request,
                $product,
                $adding ? [] : $options[$id] ?? [],
            );
            if ($adding && isset($cart[$id])) {
                $previous = app(ProductOptions::class)->validate($product, $options[$id] ?? []);
                if (!app(ProductOptions::class)->equivalent($request->user(), $previous, $newOptions)) {
                    throw ValidationException::withMessages([
                        'quantity' => __('shop::messages.products.options.cart_options_mismatch'),
                    ]);
                }
            }
            $cart[$id] = $quantity;
            $options[$id] = $newOptions;
        }
        $request->session()->put('shop.cart', $cart);
        $request->session()->put('shop.options', $options);
        if (!$request->user() && (int) $data['quantity'] > 0) {
            redirect()->setIntendedUrl(route('plugins.shop.public.cart.index'));
        }

        return redirect()->route('plugins.shop.public.cart.index');
    }

    public function checkout(Request $request, OrderService $orders, GatewayRegistry $gateways): RedirectResponse
    {
        $available = $gateways->available();
        $data = $request->validate([
            'provider' => ['nullable', Rule::in(array_keys($available))],
            'checkout_key' => ['required', 'uuid'],
            'coupon' => ['nullable', 'string', 'max:60'],
            'giftcard' => ['nullable', 'string', 'max:100'],
            'wallet_amount' => ['nullable', 'string', 'max:20'],
            'terms' => ['accepted'],
        ]);
        $provider = $data['provider'] ?? 'giftcard';
        if (!isset($available[$provider])) {
            throw ValidationException::withMessages([
                'provider' => __('shop::messages.errors.gateway_unavailable'),
            ]);
        }
        try {
            $walletAmount = ($data['wallet_amount'] ?? '') === '' ? 0 : Money::parse($data['wallet_amount']);
            if ($walletAmount > 99_999_999) {
                throw ValidationException::withMessages([]);
            }
        } catch (ValidationException) {
            throw ValidationException::withMessages([
                'wallet_amount' => __('shop::commerce.payments.policy.invalid_amount'),
            ]);
        }
        if (
            Product::query()
                ->whereIn('id', array_keys($request->session()->get('shop.cart', [])))
                ->whereNotNull('billing_interval')
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'checkout' => __('shop::messages.errors.invalid_subscription_cart'),
            ]);
        }
        $checkout = function () use ($request, $orders, $gateways, $provider, $data, $walletAmount): string {
            $order = $orders->create(
                $request->user(),
                $request->session()->get('shop.cart', []),
                $provider,
                $data['checkout_key'],
                $data['coupon'] ?? null,
                $data['giftcard'] ?? null,
                $request->session()->get('shop.options', []),
                (array) $request->input('billing', []),
                $walletAmount,
            );
            if ($order->total_minor === 0) {
                $orders->complete($order->id, $order->provider, 'zero-'.$order->reference, 0, $order->currency);

                return route('plugins.shop.public.orders.show', $order->reference);
            }
            if (!$order->isPayable()) {
                throw new HttpException(422);
            }

            return $gateways->checkout($order, $request->user());
        };
        $url = in_array('internal_balance', $gateways->get($provider)->capabilities(), true)
            ? DB::transaction($checkout)
            : $checkout();
        $request->session()->forget(['shop.cart', 'shop.options']);

        return redirect()->away($url);
    }

    public function orders(Request $request): View
    {
        $gifts = OrderItem::query()
            ->where('recipient_id', $request->user()->id)
            ->whereHas(
                'order',
                fn ($query) => $query
                    ->where('user_id', '!=', $request->user()->id)
                    ->where(fn ($query) => $query->whereNotNull('paid_at')->orWhere(fn ($query) => $query->trialing())),
            )
            ->with(['order', 'codes'])
            ->orderByDesc('id')
            ->paginate(25, ['*'], 'gifts_page')
            ->withQueryString();
        $paidPeriods = SubscriptionPayment::query()
            ->whereIn('order_id', $gifts->pluck('order_id'))
            ->where('period_end', '>', now())
            ->pluck('order_id')
            ->flip();
        $trials = Order::query()->whereIn('id', $gifts->pluck('order_id'))->trialing()->pluck('id')->flip();
        $gifts->through(function (OrderItem $item) use ($paidPeriods, $trials): array {
            $available =
                isset($trials[$item->order_id]) ||
                ($item->order->isUsable() && (!$item->order->recurring || isset($paidPeriods[$item->order_id])));

            return [
                'id' => $item->id,
                'name' => $item->name,
                'quantity' => $item->quantity,
                'received_at' => $item->order->paid_at ?? $item->order->created_at,
                'available' => $available,
                'can_download' => $item->canDownload(),
                'codes' => $available ? $item->codes->pluck('value')->all() : [],
            ];
        });

        return view('shop::default.orders.index', [
            'gifts' => $gifts,
            'orders' => Order::query()
                ->where('user_id', $request->user()->id)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(25)
                ->withQueryString(),
            'subscriptions' => Subscription::query()
                ->join('shop__order_items', 'shop__order_items.order_id', '=', 'shop__subscriptions.order_id')
                ->where('shop__subscriptions.user_id', $request->user()->id)
                ->with(['order.items', 'trial'])
                ->select('shop__subscriptions.*', 'shop__order_items.name as product_name')
                ->orderByDesc('shop__subscriptions.id')
                ->paginate(25, ['*'], 'subscriptions_page')
                ->withQueryString()
                ->through(function (Subscription $subscription): Subscription {
                    $subscription->product_name = $subscription->plan_snapshot['name'] ?? $subscription->product_name;
                    $subscription->can_change_plan =
                        app(SubscriptionPlans::class)->available($subscription) ||
                        app(SubscriptionPlans::class)->pending($subscription) !== null;

                    return $subscription;
                }),
        ]);
    }

    public function cancelSubscription(Request $request, int $id, GatewayRegistry $gateways): RedirectResponse
    {
        $subscription = Subscription::query()->where('user_id', $request->user()->id)->find($id);
        if (
            !(
                $subscription &&
                in_array(
                    $subscription->status,
                    ['active', 'past_due', 'trialing', 'suspended', 'approval_pending', 'unpaid', 'incomplete'],
                    true,
                )
            )
        ) {
            abort(404);
        }
        $gateway = $gateways->get($subscription->provider);
        if (!($gateway instanceof CancelsSubscriptions)) {
            throw new HttpException(422, __('shop::messages.site.subscriptions.cancellation_unsupported'));
        }
        /** @var CancelsSubscriptions $gateway */
        $gateway->cancelSubscription($subscription);

        return back()->with('status', __('shop::messages.site.subscriptions.subscription_cancellation'));
    }

    public function download(Request $request, string $reference, int $id): StreamedResponse
    {
        $order = $this->ownedOrder($request, $reference);
        $item = OrderItem::query()->where('order_id', $order->id)->find($id);
        if (!$item || ($item->recipient_id && $item->recipient_id !== $order->user_id)) {
            abort(404);
        }

        return $this->downloadItem($item);
    }

    public function downloadGift(Request $request, int $id): StreamedResponse
    {
        $item = OrderItem::query()
            ->where('recipient_id', $request->user()->id)
            ->whereHas('order', fn ($query) => $query->where('user_id', '!=', $request->user()->id))
            ->find($id);
        if (!$item) {
            abort(404);
        }

        return $this->downloadItem($item);
    }

    private function downloadItem(OrderItem $item): StreamedResponse
    {
        if (!$item->download_path || !str_starts_with($item->download_path, 'shop/downloads/')) {
            abort(404);
        }
        if (!$item->canDownload()) {
            throw new HttpException(403);
        }

        return Storage::disk('local')->download($item->download_path, null, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function showOrder(Request $request, string $reference): Response
    {
        $order = $this->ownedOrder($request, $reference);

        $codes = $order->isUsable()
            ? ProductCode::query()
                ->join('shop__order_items', 'shop__order_items.id', '=', 'shop__product_codes.order_item_id')
                ->where('shop__order_items.order_id', $order->id)
                ->where(
                    fn ($query) => $query
                        ->whereNull('shop__order_items.recipient_id')
                        ->orWhere('shop__order_items.recipient_id', $order->user_id),
                )
                ->select('shop__product_codes.value', 'shop__order_items.name')
                ->get()
                ->map(fn (ProductCode $code) => ['name' => $code->getAttribute('name'), 'code' => $code->value])
            : collect();

        return response()
            ->view('shop::default.orders.show', [
                'order' => $order,
                'items' => OrderItem::query()->where('order_id', $order->id)->with('recipient')->get(),
                'deliveries' => Delivery::query()
                    ->where('order_id', $order->id)
                    ->latest()
                    ->orderByDesc('id')
                    ->paginate(25, ['*'], 'deliveries_page')
                    ->withQueryString(),
                'receipts' => Receipt::query()
                    ->where('order_id', $order->id)
                    ->orderByDesc('id')
                    ->paginate(25, ['*'], 'receipts_page')
                    ->withQueryString(),
                'codes' => $codes,
            ])
            ->header('Cache-Control', 'private, no-store');
    }

    public function retry(Request $request, string $reference, GatewayRegistry $gateways): RedirectResponse
    {
        $order = $this->ownedOrder($request, $reference);
        if (!($order->isPayable() && $order->total_minor > 0)) {
            throw new HttpException(422);
        }

        return redirect()->away(
            $request->routeIs('plugins.shop.public.guests.*')
                ? $gateways->guestCheckout(
                    $order,
                    app(GuestAuth::class)->guest($request->session()->get('shop.guest_token')),
                )
                : $gateways->checkout($order, $request->user()),
        );
    }

    public function paypalReturn(
        Request $request,
        string $reference,
        PayPalService $payments,
        OrderService $orders,
    ): RedirectResponse {
        $order = $this->ownedOrder($request, $reference);
        if ($order->provider !== 'paypal') {
            throw new HttpException(422);
        }
        if (!$order->paid_at) {
            $result = $payments->capture($order);
            $capture = $result['purchase_units'][0]['payments']['captures'][0] ?? [];
            if (
                !(
                    ($result['id'] ?? '') === $order->provider_reference &&
                    ($result['status'] ?? '') === 'COMPLETED' &&
                    ($capture['status'] ?? '') === 'COMPLETED' &&
                    is_string($capture['id'] ?? null) &&
                    $capture['id'] !== '' &&
                    strlen($capture['id']) <= 180 &&
                    is_string($capture['amount']['value'] ?? null) &&
                    is_string($capture['amount']['currency_code'] ?? null)
                )
            ) {
                throw new HttpException(422);
            }
            $orders->complete(
                $order->id,
                'paypal',
                $capture['id'],
                $payments->minor($capture['amount']['value']),
                $capture['amount']['currency_code'],
                null,
                $capture['id'],
                'capture',
            );
        }

        return redirect()->route(
            $request->routeIs('plugins.shop.public.guests.*')
                ? 'plugins.shop.public.guests.orders.show'
                : 'plugins.shop.public.orders.show',
            $reference,
        );
    }

    public function stripeWebhook(Request $request, StripeService $payments, OrderService $orders): JsonResponse
    {
        $event = $payments->verifyWebhook($request);
        if (($event['type'] ?? '') === 'checkout.session.expired') {
            $session = $event['data']['object'];
            if (
                ($session['mode'] ?? null) === 'payment' &&
                ($session['status'] ?? null) === 'expired' &&
                ($session['payment_status'] ?? null) === 'unpaid' &&
                is_string($session['id'] ?? null) &&
                str_starts_with($session['id'], 'cs_') &&
                Str::isUuid($session['client_reference_id'] ?? null)
            ) {
                $order = Order::query()
                    ->where('provider', 'stripe')
                    ->where('provider_reference', $session['id'])
                    ->where('reference', $session['client_reference_id'])
                    ->first();
                if ($order) {
                    $orders->cancel($order->id);
                }
            }

            return response()->json(['received' => true]);
        }
        if (in_array($event['type'] ?? '', ['customer.updated', 'customer.deleted'], true)) {
            app(SubscriptionService::class)->customerUpdated($event['data']['object']);

            return response()->json(['received' => true]);
        }
        if (($event['type'] ?? '') === 'setup_intent.succeeded') {
            app(SubscriptionService::class)->setupCompleted($event['data']['object']);

            return response()->json(['received' => true]);
        }
        if (
            in_array(
                $event['type'] ?? '',
                ['checkout.session.completed', 'checkout.session.async_payment_succeeded'],
                true,
            )
        ) {
            $session = $event['data']['object'];
            if (($session['mode'] ?? '') === 'setup') {
                app(SubscriptionService::class)->setupCompleted($session);

                return response()->json(['received' => true]);
            }
            if (($session['payment_status'] ?? '') === 'paid' && ($session['mode'] ?? 'payment') !== 'subscription') {
                if (
                    !Str::isUuid($session['client_reference_id'] ?? null) ||
                    !is_string($session['id'] ?? null) ||
                    !str_starts_with($session['id'], 'cs_') ||
                    !is_int($session['amount_total'] ?? null) ||
                    !is_string($session['currency'] ?? null)
                ) {
                    throw new HttpException(422);
                }
                $order = Order::query()
                    ->where('provider', 'stripe')
                    ->where('provider_reference', $session['id'])
                    ->where('reference', $session['client_reference_id'])
                    ->first();
                if ($order === null) {
                    throw new HttpException(422);
                }
                $orders->complete(
                    $order->id,
                    'stripe',
                    $event['id'],
                    $session['amount_total'],
                    $session['currency'],
                    null,
                    is_string($session['payment_intent'] ?? null) ? $session['payment_intent'] : null,
                    'payment_intent',
                );
            }
        }
        if (($event['type'] ?? '') === 'invoice.paid') {
            app(SubscriptionService::class)->invoice($payments->invoice($event['data']['object']['id']), $payments);
        }
        if (($event['type'] ?? '') === 'invoice.payment_failed') {
            $invoice = $payments->invoice($event['data']['object']['id']);
            $reference =
                $invoice['subscription'] ?? ($invoice['parent']['subscription_details']['subscription'] ?? null);
            $reference = is_array($reference) ? $reference['id'] ?? null : $reference;
            if (is_string($reference) && $reference !== '') {
                app(SubscriptionService::class)->synchronize($payments->subscription($reference));
            }
        }
        if (
            in_array(
                $event['type'] ?? '',
                [
                    'customer.subscription.created',
                    'customer.subscription.updated',
                    'customer.subscription.pending_update_applied',
                    'customer.subscription.pending_update_expired',
                    'customer.subscription.deleted',
                    'customer.subscription.paused',
                    'customer.subscription.resumed',
                ],
                true,
            )
        ) {
            app(SubscriptionService::class)->synchronize($payments->subscription($event['data']['object']['id']));
        }
        if (in_array($event['type'] ?? '', ['refund.created', 'refund.updated', 'refund.failed'], true)) {
            app(RefundService::class)->webhookRefund(
                'stripe',
                $payments->getRefund($event['data']['object']['id']),
                $event['id'],
            );
        }
        if (str_starts_with($event['type'] ?? '', 'charge.dispute.')) {
            app(RefundService::class)->dispute('stripe', $event['data']['object']['id'], $event['id']);
        }

        return response()->json(['received' => true]);
    }

    public function paypalWebhook(Request $request, PayPalService $payments, OrderService $orders): JsonResponse
    {
        $event = $payments->verifyWebhook($request);
        if (($event['event_type'] ?? '') === 'PAYMENT.CAPTURE.COMPLETED') {
            $capture = $event['resource'];
            $providerReference = $capture['supplementary_data']['related_ids']['order_id'] ?? null;
            $captureId = $capture['id'] ?? null;
            if (!is_string($providerReference) || $providerReference === '' || !is_string($captureId) || $captureId === '') {
                throw new HttpException(422);
            }
            $order = Order::query()
                ->where('provider', 'paypal')
                ->where('provider_reference', $providerReference)
                ->first();
            if ($order === null) {
                throw new HttpException(422);
            }
            $remote = $payments->order($order->provider_reference);
            $remoteCapture = collect($remote['purchase_units'][0]['payments']['captures'] ?? [])->firstWhere(
                'id',
                $captureId,
            );
            if (
                !(
                    ($remote['id'] ?? '') === $order->provider_reference &&
                    $remoteCapture &&
                    ($remoteCapture['status'] ?? '') === 'COMPLETED' &&
                    is_string($remoteCapture['amount']['value'] ?? null) &&
                    is_string($remoteCapture['amount']['currency_code'] ?? null)
                )
            ) {
                throw new HttpException(422);
            }
            $orders->complete(
                $order->id,
                'paypal',
                $remoteCapture['id'],
                $payments->minor($remoteCapture['amount']['value']),
                $remoteCapture['amount']['currency_code'],
                null,
                $remoteCapture['id'],
                'capture',
            );
        }
        if (($event['event_type'] ?? '') === 'PAYMENT.SALE.COMPLETED') {
            app(SubscriptionService::class)->paypalPayment($event['resource'], $payments);
        }
        if (str_starts_with($event['event_type'] ?? '', 'BILLING.SUBSCRIPTION.')) {
            app(SubscriptionService::class)->synchronize($payments->subscription($event['resource']['id']), 'paypal');
        }
        if (
            in_array(
                $event['event_type'] ?? '',
                [
                    'PAYMENT.CAPTURE.REFUNDED',
                    'PAYMENT.REFUND.FAILED',
                    'PAYMENT.REFUND.PENDING',
                    'PAYMENT.SALE.REFUNDED',
                ],
                true,
            )
        ) {
            $reference =
                ($event['event_type'] ?? '') === 'PAYMENT.SALE.REFUNDED'
                    ? 'sale:'.$event['resource']['id']
                    : $event['resource']['id'];
            app(RefundService::class)->webhookRefund('paypal', $payments->getRefund($reference), $event['id']);
        }
        if (str_starts_with($event['event_type'] ?? '', 'CUSTOMER.DISPUTE.')) {
            app(RefundService::class)->dispute('paypal', $event['resource']['dispute_id'], $event['id']);
        }
        if (in_array($event['event_type'] ?? '', ['PAYMENT.CAPTURE.REVERSED', 'PAYMENT.SALE.REVERSED'], true)) {
            $result = $payments->reversal($event['resource']['id'], $event['event_type'] === 'PAYMENT.SALE.REVERSED');
            app(RefundService::class)->recordDispute('paypal', $result, $event['id']);
        }

        return response()->json(['received' => true]);
    }

    private function ownedOrder(Request $request, string $reference): Order
    {
        $order = Order::query()->where('reference', $reference)->first();
        $guest = $request->routeIs('plugins.shop.public.guests.*')
            ? app(GuestAuth::class)->guest($request->session()->get('shop.guest_token'))
            : null;
        if ($request->routeIs('plugins.shop.public.guests.*') && !$guest) {
            abort(404);
        }
        if (
            !(
                $order &&
                $order->hasValidBuyer() &&
                ($guest
                    ? !$order->user_id && $order->guest_id === $guest->id
                    : !$order->guest_id && $request->user() && $order->user_id === $request->user()->id)
            )
        ) {
            abort(404);
        }

        return $order;
    }
}
