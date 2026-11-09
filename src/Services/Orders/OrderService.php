<?php

namespace KraftInk\Plugins\Shop\Services\Orders;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Support\Collection;
use App\Kraft\Audit;
use App\Kraft\Currencies\CurrencyRegistry;
use App\Kraft\Games\GameRegistry;
use App\Kraft\System\OperationLock;
use App\Kraft\Wallet\Wallet;
use App\Models\Accounts\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use KraftInk\Plugins\Shop\Contracts\Payments\ProvidesInstructions;
use KraftInk\Plugins\Shop\Contracts\Payments\ReservationPolicy;
use KraftInk\Plugins\Shop\Contracts\Payments\SupportsProducts;
use KraftInk\Plugins\Shop\Models\Billing\FinancialEvent;
use KraftInk\Plugins\Shop\Models\Billing\Refund;
use KraftInk\Plugins\Shop\Models\Catalog\Product;
use KraftInk\Plugins\Shop\Models\Catalog\ProductCode;
use KraftInk\Plugins\Shop\Models\Delivery\Delivery;
use KraftInk\Plugins\Shop\Models\GiftCards\GiftCard;
use KraftInk\Plugins\Shop\Models\Orders\Order;
use KraftInk\Plugins\Shop\Models\Orders\OrderItem;
use KraftInk\Plugins\Shop\Models\Orders\PaymentEvent;
use KraftInk\Plugins\Shop\Models\Orders\SubscriptionPayment;
use KraftInk\Plugins\Shop\Services\Catalog\Catalog;
use KraftInk\Plugins\Shop\Services\Catalog\CouponRules;
use KraftInk\Plugins\Shop\Services\Catalog\ProductOptions;
use KraftInk\Plugins\Shop\Services\Catalog\PurchaseLimits;
use KraftInk\Plugins\Shop\Services\Commerce;
use KraftInk\Plugins\Shop\Services\Finance\IssuedCreditScope;
use KraftInk\Plugins\Shop\Services\Finance\WalletReturns;
use KraftInk\Plugins\Shop\Services\GiftCards;
use KraftInk\Plugins\Shop\Services\GuestAuth;
use App\Kraft\Link\CommandIdentity;
use KraftInk\Plugins\Shop\Services\Payments\GatewayRegistry;
use KraftInk\Plugins\Shop\Services\Payments\StripeService;
use KraftInk\Plugins\Shop\Services\Payments\PaymentPolicy;
use KraftInk\Plugins\Shop\Services\Pricing;
use KraftInk\Plugins\Shop\Services\PurchaseNotifications;
use KraftInk\Plugins\Shop\Services\Roles\RoleAssignments;
use KraftInk\Plugins\Shop\Services\Roles\RolePolicy;
use Throwable;
use App\Kraft\Auth\PermissionAccess;
use App\Kraft\Events\PluginEvents;
use KraftInk\Plugins\Shop\Contracts\Payments\GuestCheckout;

class OrderService
{
    public function create(
        ?User $user,
        array $cart,
        string $provider,
        string $key,
        ?string $couponCode = null,
        ?string $giftcardCode = null,
        array $options = [],
        array $billing = [],
        int $walletAmount = 0,
        ?object $guest = null,
    ): object {
        if (($user === null) === ($guest === null)) {
            throw new HttpException(422);
        }
        $operationLease = app(OperationLock::class)->acquireShared();
        try {
            $guest = $guest ? app(GuestAuth::class)->buyer($guest->id) : null;
            $billing = app(Commerce\BillingDetails::class)->capture($user, $billing, $guest?->email_address);
            $this->expire();
            $cart = array_map('intval', $cart);
            ksort($cart, SORT_NUMERIC);
            $payload = [
                $cart,
                $provider,
                strtoupper(trim($couponCode ?? '')),
                strtoupper(trim($giftcardCode ?? '')),
                $options,
                $billing,
            ];
            if ($walletAmount !== 0) {
                $payload[] = ['wallet_minor' => $walletAmount];
            }
            $payload = $this->canonical($payload);
            $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

            return DB::transaction(function () use (
                $user,
                $cart,
                $provider,
                $key,
                $couponCode,
                $giftcardCode,
                $options,
                $hash,
                $billing,
                $walletAmount,
                $guest,
            ) {
                $user = $user ? User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail() : null;
                $guest = $guest ? app(GuestAuth::class)->buyer($guest->id, true) : null;
                $existing = Order::query()->where('checkout_key', $key)->first();
                if ($existing) {
                    if (
                        !($user
                            ? $existing->user_id === $user->id && !$existing->guest_id
                            : !$existing->user_id && $existing->guest_id === $guest->id)
                    ) {
                        throw new HttpException(403);
                    }
                    if (!($existing->checkout_hash && hash_equals($existing->checkout_hash, $hash))) {
                        throw new HttpException(409, __('shop::messages.products.options.checkout_changed'));
                    }

                    return $existing;
                }
                if ($user && ($user->isBanned() || $user->anonymized_at || $user->import_locked)) {
                    throw new HttpException(403);
                }
                $this->check(app(PermissionAccess::class)->allows($user, 'shop.view'), 'permission');
                $this->check(
                    !Order::query()
                        ->where($user ? 'user_id' : 'guest_id', $user?->id ?? $guest->id)
                        ->where('financial_hold', true)
                        ->exists(),
                    'financial_hold',
                );
                $this->check(count($cart) > 0 && count($cart) <= 50, 'cart');
                /** @var Collection<int, Product> $products */
                $products = Product::query()
                    ->activeGame()
                    ->whereIn('id', array_keys($cart))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $this->check($products->count() === count($cart), 'cart');
                $gateway = app(GatewayRegistry::class)->get($provider);
                if ($guest) {
                    if (
                        collect($options)->contains(
                            fn ($input) => !empty($input['recipient']) || !empty($input['recipient_id']),
                        )
                    ) {
                        throw ValidationException::withMessages(['recipient' => __('shop::gifting.member_required')]);
                    }
                    if (!app(GuestAuth::class)->enabled()) {
                        abort(404);
                    }
                    $this->check($gateway instanceof GuestCheckout, 'gateway_products');
                    if (
                        $walletAmount !== 0 ||
                        $giftcardCode ||
                        $products->contains(
                            fn ($product) => $product->billing_interval ||
                                $product->role_id ||
                                $product->wallet_credit_minor ||
                                $product->upgrade_group ||
                                $product->kind === 'giftcard',
                        )
                    ) {
                        throw ValidationException::withMessages([
                            'checkout' => __('shop::messages.guests.member_required'),
                        ]);
                    }
                }
                $this->check(
                    !($gateway instanceof SupportsProducts) || $gateway->supportsProducts($products),
                    'gateway_products',
                );
                $hasGiftcards = $products->contains(fn ($product) => $product->kind === 'giftcard');
                if (
                    $hasGiftcards &&
                    ($walletAmount > 0 ||
                        $giftcardCode ||
                        array_intersect(['internal_balance', 'zero_total'], $gateway->capabilities()))
                ) {
                    throw ValidationException::withMessages([
                        'checkout' => __('shop::commerce.giftcards.invalid_payment'),
                    ]);
                }
                $hasCredits = $products->contains(fn ($product) => $product->wallet_credit_minor > 0);
                $this->check(
                    !$hasCredits ||
                        (!$couponCode &&
                            !$giftcardCode &&
                            $walletAmount === 0 &&
                            !array_intersect(['internal_balance', 'zero_total'], $gateway->capabilities())),
                    'wallet_credit_payment',
                );
                $recurring = $products->contains(fn ($product) => $product->billing_interval !== null);
                $this->check(
                    !$recurring ||
                        ($products->count() === 1 &&
                            (int) reset($cart) === 1 &&
                            in_array(
                                'subscriptions',
                                app(GatewayRegistry::class)->get($provider)->capabilities(),
                                true,
                            ) &&
                            !$couponCode &&
                            !$giftcardCode &&
                            $walletAmount === 0),
                    'subscription_cart',
                );
                $autoExpire = !(
                    $recurring &&
                    in_array('persistent_approval', app(GatewayRegistry::class)->get($provider)->capabilities(), true)
                );
                $subtotal = 0;
                $currency = $products->first()->currency;
                app(CurrencyRegistry::class)->assertSupported($currency);
                if (!app(GatewayRegistry::class)->supportsCurrency($provider, $currency)) {
                    throw ValidationException::withMessages([
                        'provider' => __('shop::commerce.payments.currencies.gateway_incompatible', [
                            'currency' => $currency,
                        ]),
                    ]);
                }
                $basePrices = [];
                $deliverySnapshots = [];
                $itemOptions = [];
                $beneficiaries = [];
                $commandsSnapshots = [];
                $refundCommandsSnapshots = [];
                foreach ($products as $product) {
                    $itemOptions[$product->id] = app(ProductOptions::class)->validate(
                        $product,
                        $options[$product->id] ?? [],
                    );
                    $beneficiaries[$product->id] = app(ProductOptions::class)->beneficiary(
                        $user,
                        $itemOptions[$product->id],
                        true,
                    );
                }
                foreach ($products as $product) {
                    $this->check(
                        !$product->wallet_credit_minor || $product->wallet_currency === app(Wallet::class)->currency(),
                        'wallet_currency',
                    );
                    app(RolePolicy::class)->assertSellable($product->role_id);
                    if (
                        $product->kind === 'giftcard' &&
                        ($product->billing_interval ||
                            $product->role_id ||
                            $product->wallet_credit_minor ||
                            $product->upgrade_group ||
                            $product->uses_codes ||
                            $product->download_path ||
                            json_decode($product->commands ?: '[]', true) ||
                            json_decode($product->refund_commands ?: '[]', true) ||
                            $product->server_id ||
                            ($product->variables ?? []))
                    ) {
                        throw ValidationException::withMessages([
                            'checkout' => __('shop::commerce.giftcards.invalid_product'),
                        ]);
                    }
                    $quantity = (int) $cart[$product->id];
                    $this->check(
                        $quantity >= 1 && $quantity <= 99 && $product->enabled && $product->currency === $currency,
                        'cart',
                    );
                    $this->check(
                        !$product->required_permission ||
                            app(PermissionAccess::class)->allows($user, $product->required_permission),
                        'permission',
                    );
                    $this->check($product->stock === null || $product->stock >= $quantity, 'stock');
                    $beneficiary = $beneficiaries[$product->id];
                    if ($beneficiary && !app(SubscriptionService::class)->canSubscribe($beneficiary, $product, true)) {
                        throw ValidationException::withMessages([
                            'checkout' => __('shop::subscriptions.already_subscribed'),
                        ]);
                    }
                    if ($beneficiary && $beneficiary->id !== $user?->id) {
                        $this->check(
                            Product::query()->visibleTo($beneficiary)->whereKey($product->id)->exists(),
                            'permission',
                        );
                    }
                    $commands = app(ProductOptions::class)->commands(
                        json_decode($product->commands ?: '[]', true),
                        $itemOptions[$product->id]['variables'],
                    );
                    $commandsSnapshots[$product->id] = $commands;
                    $refundCommandsSnapshots[$product->id] = app(ProductOptions::class)->commands(
                        json_decode($product->refund_commands ?: '[]', true),
                        $itemOptions[$product->id]['variables'],
                    );
                    $deliveryCommands = array_merge($commands, $refundCommandsSnapshots[$product->id]);
                    $target = $itemOptions[$product->id]['server_id'];
                    $deliverySnapshots[$product->id] = $commands
                        ? ($guest
                            ? app(CommandIdentity::class)->resolveGuest(
                                $target,
                                $deliveryCommands,
                                (bool) $product->requires_online,
                                $options[$product->id]['guest_target'] ?? [],
                            )
                            : app(CommandIdentity::class)->resolve(
                                $beneficiary,
                                $target,
                                $deliveryCommands,
                                (bool) $product->requires_online,
                            ))
                        : [
                            'server_id' => $target,
                            'game' => $product->game ?: app(GameRegistry::class)->activeId(),
                            'player_uuid' => null,
                            'player_name' => null,
                            'requires_online' => false,
                        ];
                    app(PurchaseLimits::class)->assert(
                        $beneficiary,
                        $product,
                        $quantity,
                        $products->filter(fn ($other) => $beneficiaries[$other->id]?->id === $beneficiary?->id),
                        $guest?->id,
                    );
                    $this->check(
                        !$recurring || ($product->stock === null && !$product->uses_codes),
                        'subscription_stock',
                    );
                    $price = $itemOptions[$product->id]['price_minor'];
                    if ($product->upgrade_group) {
                        $this->check(
                            $quantity === 1 && !$recurring && !$product->wallet_credit_minor && !$product->custom_price,
                            'upgrade',
                        );
                    }
                    $basePrices[$product->id] = $price;
                    $subtotal += $price * $quantity;
                }
                $this->check($subtotal <= 99999999, 'total');
                $couponRules = app(CouponRules::class);
                $coupon = $couponRules->lockForCheckout($couponCode, $subtotal, $currency, $user, $guest?->id);
                $couponShares = $coupon ? $couponRules->discountShares($coupon, $products, $basePrices, $cart) : [];
                $discount = array_sum($couponShares);
                $promotions = [];
                foreach ($products as $product) {
                    $promotions[$product->id] = $couponShares[$product->id] ?? 0;
                }
                $privileged = ['snapshots' => []];
                if ($user) {
                    $privileged = app(Pricing\PrivilegedPricing::class)->quote(
                        $user,
                        $products,
                        $basePrices,
                        $cart,
                        $couponShares,
                    );
                    $promotions = $privileged['promotions'];
                    $couponShares = $privileged['coupon_shares'];
                    $discount = array_sum($promotions);
                    if ($coupon && array_sum($couponShares) === 0) {
                        $coupon = null;
                    }
                }
                $pricing = [];
                $upgradeCredit = 0;
                $groups = [];
                foreach ($products as $product) {
                    $pricing[$product->id] = $beneficiaries[$product->id]
                        ? app(Pricing\UpgradeLedger::class)->quote(
                            $beneficiaries[$product->id]->id,
                            $product,
                            $basePrices[$product->id] * $cart[$product->id] - ($promotions[$product->id] ?? 0),
                        )
                        : null;
                    if (
                        $product->kind === 'giftcard' &&
                        $cart[$product->id] >
                            $basePrices[$product->id] * $cart[$product->id] - $promotions[$product->id]
                    ) {
                        throw ValidationException::withMessages([
                            'checkout' => __('shop::commerce.giftcards.positive_value'),
                        ]);
                    }
                    if ($pricing[$product->id]) {
                        $group = $pricing[$product->id]['group_id'].':'.$pricing[$product->id]['beneficiary_id'];
                        $this->check(!in_array($group, $groups, true), 'upgrade_pending');
                        $groups[] = $group;
                        $upgradeCredit += $pricing[$product->id]['credit_minor'];
                    }
                }
                $giftcard = null;
                $giftcardAmount = 0;
                if ($walletAmount < 0 || $walletAmount > $subtotal - $discount - $upgradeCredit) {
                    throw ValidationException::withMessages([
                        'wallet_amount' => __('shop::commerce.wallet.invalid_contribution'),
                    ]);
                }
                if ($walletAmount > 0 && $currency !== app(Wallet::class)->currency()) {
                    throw ValidationException::withMessages([
                        'wallet_amount' => __('shop::commerce.wallet.incompatible'),
                    ]);
                }
                if ($walletAmount > 0 && $walletAmount > app(Wallet::class)->balance($user)) {
                    throw ValidationException::withMessages([
                        'wallet_amount' => __('site.account.wallet.insufficient_balance'),
                    ]);
                }
                if ($giftcardCode) {
                    $giftcard = GiftCard::query()
                        ->where('code_hash', hash('sha256', strtoupper(trim($giftcardCode))))
                        ->lockForUpdate()
                        ->first();
                    $this->check(
                        $giftcard?->isSpendable() && $giftcard->currency === $currency,
                        'giftcard',
                    );
                    $this->check(
                        (!$giftcard->owner_id || $giftcard->owner_id === $user->id) &&
                            (!$giftcard->source_item_id || $giftcard->owner_id === $user->id),
                        'giftcard',
                    );
                    if (!$giftcard->owner_id) {
                        GiftCard::query()
                            ->where('id', $giftcard->id)
                            ->update(['owner_id' => $user->id, 'claimed_at' => now()]);
                    }
                    app(IssuedCreditScope::class)->assert($giftcard, $products);
                    $giftcardAmount = min(
                        $subtotal - $discount - $upgradeCredit - $walletAmount,
                        $giftcard->balance_minor,
                    );
                    GiftCard::query()->where('id', $giftcard->id)->decrement('balance_minor', $giftcardAmount);
                }
                $providerAmount = $subtotal - $discount - $upgradeCredit - $giftcardAmount - $walletAmount;
                app(PaymentPolicy::class)->assertCheckout(
                    $provider,
                    $currency,
                    $providerAmount,
                    $walletAmount,
                    $giftcardAmount,
                );
                $reference = (string) Str::uuid();
                $commercial = app(Commerce\ReceiptService::class)->configuration($user, $guest?->locale) + [
                    'billing' => $billing,
                ];
                if ($provider === 'stripe' && $recurring) {
                    $commercial['stripe_customer'] = app(StripeService::class)->checkoutCustomerContext($user);
                }
                $id = Order::query()->insertGetId([
                    'reference' => $reference,
                    'checkout_key' => $key,
                    'checkout_hash' => $hash,
                    'user_id' => $user?->id,
                    'guest_id' => $guest?->id,
                    'status' => 'pending',
                    'provider' => $provider,
                    'recurring' => $recurring,
                    'auto_expire' => $autoExpire,
                    'currency' => $currency,
                    'subtotal_minor' => $subtotal,
                    'discount_minor' => $discount,
                    'upgrade_credit_minor' => $upgradeCredit,
                    'giftcard_minor' => $giftcardAmount,
                    'wallet_minor' => $walletAmount,
                    'total_minor' => $providerAmount,
                    'coupon_id' => $coupon?->id,
                    'giftcard_id' => $giftcard?->id,
                    'commercial_snapshot' => json_encode($commercial, JSON_THROW_ON_ERROR),
                    'payment_instructions' => $gateway instanceof ProvidesInstructions ? $gateway->instructions() : null,
                    'expires_at' => now()->addSeconds(
                        $gateway instanceof ReservationPolicy
                            ? max(60, min(604800, $gateway->reservationSeconds()))
                            : 2400,
                    ),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                if ($walletAmount > 0) {
                    app(Wallet::class)->debit(
                        $user,
                        $walletAmount,
                        'shop:wallet:payment:'.$reference,
                        'shop.payment',
                        ['order_id' => $id],
                    );
                }
                foreach ($products as $product) {
                    $quantity = (int) $cart[$product->id];
                    $localized = app(Catalog::class)->localize(clone $product);
                    $itemId = OrderItem::query()->insertGetId(
                        [
                            'order_id' => $id,
                            'product_id' => $product->id,
                            'category_id' => $product->category_id,
                            'kind' => $product->kind,
                            'giftcard_days' => $product->giftcard_days,
                            'giftcard_recipient_id' => $itemOptions[$product->id]['giftcard_recipient_id'],
                            'name' => $localized->name,
                            'quantity' => $quantity,
                            'unit_minor' => $basePrices[$product->id],
                            'recipient_id' => $beneficiaries[$product->id]?->id,
                            'promotion_minor' => $promotions[$product->id] ?? 0,
                            'discount_snapshot' => json_encode(
                                [
                                    'coupon_id' => $coupon?->id,
                                    'coupon_minor' => $couponShares[$product->id] ?? 0,
                                    'privileged' => $privileged['snapshots'][$product->id] ?? null,
                                ],
                                JSON_THROW_ON_ERROR,
                            ),
                            'upgrade_credit_minor' => $pricing[$product->id]['credit_minor'] ?? 0,
                            'paid_minor' => $basePrices[$product->id] * $quantity -
                                ($promotions[$product->id] ?? 0) -
                                ($pricing[$product->id]['credit_minor'] ?? 0),
                            'pricing_snapshot' => $pricing[$product->id]
                                ? json_encode($pricing[$product->id], JSON_THROW_ON_ERROR)
                                : null,
                            'variables' => json_encode($itemOptions[$product->id]['variables'], JSON_THROW_ON_ERROR),
                            'commands' => json_encode($commandsSnapshots[$product->id], JSON_THROW_ON_ERROR),
                            'refund_commands' => json_encode(
                                $refundCommandsSnapshots[$product->id],
                                JSON_THROW_ON_ERROR,
                            ),
                            'wallet_credit_minor' => $product->wallet_credit_minor,
                            'wallet_currency' => $product->wallet_currency,
                            'role_id' => $product->role_id,
                            'role_days' => $product->role_days,
                            'billing_interval' => $product->billing_interval,
                            'trial_days' => $itemOptions[$product->id]['trial_requested']
                                ? (int) $product->trial_days
                                : 0,
                            'trial_requires_payment_method' => $product->trial_requires_payment_method,
                            'subscription_group' => $product->subscription_group,
                            'upgrade_group' => $product->upgrade_group,
                            'upgrade_weight' => $product->upgrade_weight,
                            'download_path' => $product->download_path,
                        ] + $deliverySnapshots[$product->id],
                    );
                    if ($pricing[$product->id]) {
                        app(Pricing\UpgradeLedger::class)->reserve($id, $pricing[$product->id]);
                    }
                    if ($product->uses_codes) {
                        $this->check(!$recurring, 'codes_subscription');
                        $codes = ProductCode::query()
                            ->where('product_id', $product->id)
                            ->whereNull('order_item_id')
                            ->orderBy('id')
                            ->limit($quantity)
                            ->lockForUpdate()
                            ->pluck('id');
                        $this->check($codes->count() === $quantity, 'codes_stock');
                        ProductCode::query()
                            ->whereIn('id', $codes)
                            ->update(['order_item_id' => $itemId]);
                    }
                    if ($product->stock !== null) {
                        Product::query()->where('id', $product->id)->decrement('stock', $quantity);
                    }
                }

                $order = Order::query()->find($id);
                foreach ($order->items as $item) {
                    if ($item->trial_days > 0) {
                        app(SubscriptionTrials::class)->reserve($order, $item);
                    }
                }

                return $order;
            }, 3);
        } finally {
            $operationLease->release();
        }
    }

    public function complete(
        int $id,
        string $provider,
        string $eventId,
        int $amount,
        string $currency,
        ?string $periodEnd = null,
        ?string $paymentReference = null,
        ?string $paymentKind = null,
    ): void {
        $operationLease = app(OperationLock::class)->acquireShared();
        try {
            $buyerOrder = Order::query()->find($id);
            if (!($buyerOrder && ($buyerOrder->user_id === null) !== ($buyerOrder->guest_id === null))) {
                abort(404);
            }
            $userId = $buyerOrder->user_id;
            $guestId = $buyerOrder->guest_id;
            $process = DB::transaction(function () use (
                $id,
                $userId,
                $guestId,
                $provider,
                $eventId,
                $amount,
                $currency,
                $paymentReference,
                $paymentKind,
            ) {
                $userId
                    ? User::query()->whereKey($userId)->lockForUpdate()->firstOrFail()
                    : app(GuestAuth::class)->buyer($guestId, true);
                $order = Order::query()->where('id', $id)->lockForUpdate()->first();
                if (
                    !(
                        $order &&
                        $order->provider === $provider &&
                        $order->total_minor === $amount &&
                        $order->currency === strtoupper($currency)
                    )
                ) {
                    throw new HttpException(422);
                }
                $event = PaymentEvent::query()->where('provider', $provider)->where('event_id', $eventId)->first();
                if ($event && $event->order_id !== $id) {
                    throw new HttpException(409);
                }
                if ($order->paid_at) {
                    if (
                        $paymentReference !== null &&
                        $order->payment_reference !== null &&
                        $order->payment_reference !== $paymentReference
                    ) {
                        throw new HttpException(409);
                    }
                    if ($paymentReference !== null && $order->payment_reference === null) {
                        $order->update(['payment_reference' => $paymentReference, 'payment_kind' => $paymentKind]);
                    }

                    return $order->pricing_review_reason === 'processing';
                }
                if (!$event) {
                    PaymentEvent::query()->insert([
                        'provider' => $provider,
                        'event_id' => $eventId,
                        'order_id' => $id,
                        'created_at' => now(),
                    ]);
                }
                $late = $order->status !== 'pending' || $order->released_at;
                $actualGiftcard = $order->released_at ? 0 : $order->giftcard_minor;
                $actualWallet = $order->released_at ? 0 : $order->wallet_minor ?? 0;
                $configuration = $order->commercial_snapshot;
                $reviewReason = $late ? 'late_payment' : 'processing';
                if (!$configuration) {
                    $reviewReason = 'fulfillment_failed';
                }
                $requiresReview = $reviewReason !== 'processing';
                if ($late && $configuration) {
                    $configuration['payment_review'] = [
                        'reason' => 'late_payment',
                        'expected_minor' => $order->subtotal_minor - $order->discount_minor - $order->upgrade_credit_minor,
                        'expected_giftcard_minor' => $order->giftcard_minor,
                        'expected_wallet_minor' => $order->wallet_minor ?? 0,
                        'received_minor' => $amount + $actualGiftcard + $actualWallet,
                    ];
                    $weights = OrderItem::query()
                        ->where('order_id', $id)
                        ->orderBy('id')
                        ->get()
                        ->mapWithKeys(
                            fn (OrderItem $item) => [
                                $item->id => (int) ($item->paid_minor ?? $item->unit_minor * $item->quantity),
                            ],
                        )
                        ->all();
                    $actual = Pricing\Allocation::proportional(
                        min($amount + $actualGiftcard + $actualWallet, array_sum($weights)),
                        $weights,
                    );
                    foreach ($actual as $itemId => $paid) {
                        OrderItem::query()
                            ->where('id', $itemId)
                            ->update(['paid_minor' => $paid]);
                    }
                }
                $values = [
                    'status' => 'paid',
                    'paid_at' => now(),
                    'giftcard_minor' => $actualGiftcard,
                    'wallet_minor' => $actualWallet,
                    'pricing_hold' => true,
                    'pricing_review_reason' => $reviewReason,
                    'financial_review' => $order->financial_review || $requiresReview,
                    'updated_at' => now(),
                ];
                if ($late && $configuration) {
                    $values['commercial_snapshot'] = json_encode($configuration, JSON_THROW_ON_ERROR);
                }
                if ($paymentReference !== null) {
                    $values['payment_reference'] = $paymentReference;
                    $values['payment_kind'] = $paymentKind;
                }
                Order::query()->where('id', $id)->update($values);
                FinancialEvent::query()->insert([
                    'order_id' => $id,
                    'event_key' => $provider.':payment:'.$eventId,
                    'type' => 'payment',
                    'reference' => $paymentReference ?: ($order->provider_reference ?: $order->reference),
                    'status' => 'received',
                    'amount_minor' => $amount,
                    'currency' => $order->currency,
                    'created_at' => now(),
                ]);
                Audit::record('shop.order.paid', [
                    'order_id' => $id,
                    'user_id' => $userId,
                    'guest_id' => $guestId,
                    'reference' => $order->reference,
                    'amount_minor' => $amount,
                    'currency' => $order->currency,
                ]);
                app(PluginEvents::class)->publish(
                    'shop.order.paid',
                    [
                        'order_id' => $id,
                        'user_id' => $userId,
                        'guest_id' => $guestId,
                        'amount_minor' => $amount,
                        'currency' => $order->currency,
                        'provider' => $provider,
                        'requires_review' => $order->financial_review || $requiresReview,
                    ],
                    'shop.order.paid:'.$id,
                );
                if ($requiresReview) {
                    app(Pricing\UpgradeLedger::class)->release($id);
                }

                return !$requiresReview;
            }, 3);
            if (!$process) {
                $order = Order::query()->find($id);
                if (in_array($order->pricing_review_reason, ['late_payment', 'fulfillment_failed'], true)) {
                    $this->recordReview($id);
                }

                return;
            }
            try {
                DB::transaction(function () use ($id, $userId, $guestId, $periodEnd) {
                    $user = $userId ? User::query()->whereKey($userId)->lockForUpdate()->firstOrFail() : null;
                    if ($guestId) {
                        app(GuestAuth::class)->buyer($guestId, true);
                    }
                    $order = Order::query()->where('id', $id)->lockForUpdate()->first();
                    if ($order->pricing_review_reason !== 'processing') {
                        return;
                    }
                    if ($user && ($user->isBanned() || $user->anonymized_at || $user->import_locked)) {
                        throw new HttpException(403);
                    }
                    if ($order->financial_hold || $order->giftcard_hold) {
                        throw new HttpException(422, __('shop::commerce.refunds.financial_hold'));
                    }
                    if ($order->recurring && (!$periodEnd || now()->gte($periodEnd))) {
                        throw new HttpException(422);
                    }
                    /** @var OrderItem $item */
                    foreach (OrderItem::query()->where('order_id', $id)->get() as $item) {
                        if (
                            !$user &&
                            ($order->recurring ||
                                $item->role_id ||
                                $item->wallet_credit_minor ||
                                $item->upgrade_group ||
                                $item->kind === 'giftcard')
                        ) {
                            throw new HttpException(422);
                        }
                        app(RolePolicy::class)->assertSellable($item->role_id);
                        $beneficiary = app(ProductOptions::class)->beneficiary(
                            $user,
                            ['recipient_id' => $item->recipient_id],
                            true,
                        );
                        app(GiftCards\CardService::class)->issue($order, $item);
                        if ($item->wallet_credit_minor > 0) {
                            if ($item->wallet_currency !== app(Wallet::class)->currency()) {
                                throw new HttpException(422, __('shop::commerce.wallet.incompatible'));
                            }
                            app(Wallet::class)->credit(
                                $beneficiary,
                                $item->wallet_credit_minor * $item->quantity,
                                'shop:credit:'.$item->id,
                                'shop.credit',
                                ['order_id' => $id, 'order_item_id' => $item->id],
                            );
                        }
                        app(RoleAssignments::class)->grant($order, $item, $periodEnd);
                        for ($quantity = 0; $quantity < $item->quantity; $quantity++) {
                            foreach (json_decode($item->commands ?: '[]', true) as $command) {
                                Delivery::query()->insert([
                                    'id' => (string) Str::uuid(),
                                    'order_id' => $id,
                                    'order_item_id' => $item->id,
                                    'kind' => 'grant',
                                    'user_id' => $user?->id,
                                    'guest_id' => $guestId,
                                    'command' => app(CommandIdentity::class)->expand($item, $command),
                                    'server_id' => $item->server_id,
                                    'game' => $item->game,
                                    'player_uuid' => $item->player_uuid,
                                    'player_name' => $item->player_name,
                                    'requires_online' => $item->requires_online,
                                    'available_at' => now(),
                                    'status' => 'pending',
                                    'attempts' => 0,
                                    'created_at' => now(),
                                ]);
                            }
                        }
                    }
                    Order::query()
                        ->where('id', $id)
                        ->where('pricing_review_reason', 'processing')
                        ->update(['pricing_hold' => false, 'pricing_review_reason' => null]);
                    app(GiftCards\CardService::class)->reconcileOrder($id);
                    app(Pricing\UpgradeLedger::class)->reconcileOrder($id);
                    app(Commerce\ReceiptService::class)->payment($id);
                    app(PurchaseNotifications::class)->paid($id);
                }, 3);
            } catch (Throwable $exception) {
                Order::query()
                    ->where('id', $id)
                    ->where('pricing_review_reason', 'processing')
                    ->update([
                        'pricing_hold' => true,
                        'pricing_review_reason' => 'fulfillment_failed',
                        'financial_review' => true,
                        'updated_at' => now(),
                    ]);
                app(Pricing\UpgradeLedger::class)->release($id);
                report($exception);
                $this->recordReview($id);
            }
        } finally {
            $operationLease->release();
        }
    }

    private function recordReview(int $id): void
    {
        DB::transaction(function () use ($id) {
            $order = Order::query()->where('id', $id)->lockForUpdate()->first();
            $key = 'shop:payment-review:'.$id;
            if (FinancialEvent::query()->where('event_key', $key)->exists()) {
                return;
            }
            FinancialEvent::query()->insert([
                'order_id' => $id,
                'event_key' => $key,
                'type' => 'payment_review',
                'reference' => $order->reference,
                'status' => $order->pricing_review_reason,
                'amount_minor' => $order->total_minor,
                'currency' => $order->currency,
                'created_at' => now(),
            ]);
            if ($order->commercial_snapshot) {
                app(Commerce\ReceiptService::class)->payment($id);
            }
            app(PurchaseNotifications::class)->paid($id);
            Audit::record('shop.order.payment_review', ['order_id' => $id, 'reason' => $order->pricing_review_reason]);
        }, 3);
    }

    public function recoverProcessing(int $limit = 100): int
    {
        $operationLease = app(OperationLock::class)->acquireShared();
        try {
            /** @var Collection<int, Order> $orders */
            $orders = Order::query()
                ->where('status', 'paid')
                ->where('pricing_review_reason', 'processing')
                ->orderBy('id')
                ->limit(max(1, min(100, $limit)))
                ->get();
            $count = 0;
            $started = microtime(true);
            foreach ($orders as $order) {
                if (microtime(true) - $started >= 20) {
                    break;
                }
                $event = $order->paymentEvents()
                    ->where('provider', $order->provider)
                    ->orderBy('id')
                    ->first();
                if (!$event) {
                    continue;
                }
                $period = $order->recurring
                    ? SubscriptionPayment::query()->where('order_id', $order->id)->value('period_end')
                    : null;
                $this->complete(
                    $order->id,
                    $order->provider,
                    $event->event_id,
                    $order->total_minor,
                    $order->currency,
                    $period,
                );
                $count++;
            }

            return $count;
        } finally {
            $operationLease->release();
        }
    }

    public function retryFulfillment(int $id, User $actor): void
    {
        $operationLease = app(OperationLock::class)->acquireShared();
        try {
            if (!$actor->hasPermission('shop.refunds.manage')) {
                throw new HttpException(403);
            }
            $event = DB::transaction(function () use ($id, $actor) {
                $order = Order::query()->where('id', $id)->lockForUpdate()->first();
                if (
                    !(
                        $order &&
                        $order->status === 'paid' &&
                        $order->pricing_review_reason === 'fulfillment_failed' &&
                        !$order->released_at &&
                        !$order->refunded_minor &&
                        !$order->financial_hold &&
                        !$order->giftcard_hold
                    )
                ) {
                    throw new HttpException(422, __('shop::commerce.upgrades.retry_unavailable'));
                }
                if (
                    Refund::query()
                        ->where('order_id', $id)
                        ->whereIn('status', ['pending', 'processing', 'succeeded'])
                        ->exists()
                ) {
                    throw new HttpException(422, __('shop::commerce.upgrades.retry_unavailable'));
                }
                $event = $order->paymentEvents()
                    ->where('provider', $order->provider)
                    ->orderBy('id')
                    ->first();
                if ($event === null) {
                    throw new HttpException(409);
                }
                /** @var OrderItem $item */
                foreach (OrderItem::query()->where('order_id', $id)->orderBy('id')->get() as $item) {
                    app(RolePolicy::class)->assertManage($actor, $item->role_id);
                    if ($item->wallet_credit_minor > 0 && !$actor->hasPermission('wallet.manage')) {
                        throw new HttpException(403);
                    }
                    if ($item->pricing_snapshot) {
                        app(Pricing\UpgradeLedger::class)->reserve($id, $item->pricing_snapshot);
                    }
                }
                Order::query()
                    ->where('id', $id)
                    ->update(['pricing_review_reason' => 'processing', 'updated_at' => now()]);
                Audit::record('shop.order.fulfillment_retry', ['order_id' => $id], $actor);

                return $event;
            }, 3);
            $order = Order::query()->find($id);
            $period = $order->recurring
                ? SubscriptionPayment::query()->where('order_id', $id)->value('period_end')
                : null;
            $this->complete($id, $order->provider, $event->event_id, $order->total_minor, $order->currency, $period);
        } finally {
            $operationLease->release();
        }
    }

    public function cancel(int $id): void
    {
        $operationLease = app(OperationLock::class)->acquireShared();
        try {
            DB::transaction(function () use ($id) {
                $order = Order::query()->where('id', $id)->lockForUpdate()->first();
                if (!$order || $order->status !== 'pending' || $order->released_at) {
                    return;
                }
                /** @var OrderItem $item */
                foreach (OrderItem::query()->where('order_id', $id)->orderBy('product_id')->get() as $item) {
                    ProductCode::query()
                        ->where('order_item_id', $item->id)
                        ->update(['order_item_id' => null]);
                    if ($item->product_id) {
                        Product::query()
                            ->where('id', $item->product_id)
                            ->whereNotNull('stock')
                            ->increment('stock', $item->quantity);
                    }
                }
                if ($order->giftcard_id && $order->giftcard_minor) {
                    GiftCard::query()
                        ->where('id', $order->giftcard_id)
                        ->increment('balance_minor', $order->giftcard_minor);
                }
                app(WalletReturns::class)->queue($order, (int) ($order->wallet_minor ?? 0), 'cancel:'.$order->id);
                app(Pricing\UpgradeLedger::class)->release($id);
                Order::query()
                    ->where('id', $id)
                    ->update(['status' => 'cancelled', 'released_at' => now(), 'updated_at' => now()]);
                app(GiftCards\CardService::class)->reconcileOrder($id);
            }, 3);
        } finally {
            $operationLease->release();
        }
    }

    public function expire(): void
    {
        $operationLease = app(OperationLock::class)->acquireShared();
        try {
            Order::query()
                ->where('status', 'pending')
                ->where('auto_expire', true)
                ->where('expires_at', '<', now())
                ->orderBy('id')
                ->limit(100)
                ->pluck('id')
                ->each(fn ($id) => $this->cancel($id));
            app(WalletReturns::class)->retryPending();
        } finally {
            $operationLease->release();
        }
    }

    private function check(bool $condition, string $reason): void
    {
        if (!$condition) {
            throw ValidationException::withMessages(['checkout' => __('shop::messages.errors.invalid_'.$reason)]);
        }
    }

    private function canonical(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonical($item);
            }
        }

        return $value;
    }
}
