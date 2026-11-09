<?php

use App\Http\Middleware\NoIndex;
use Illuminate\Support\Facades\Route;
use KraftInk\Plugins\Shop\Http\Controllers\GiftCards\CardController;
use KraftInk\Plugins\Shop\Http\Controllers\GuestController;
use KraftInk\Plugins\Shop\Http\Controllers\Payments\PaysafecardController;
use KraftInk\Plugins\Shop\Http\Controllers\ReceiptController;
use KraftInk\Plugins\Shop\Http\Controllers\ShopController;
use KraftInk\Plugins\Shop\Http\Controllers\SubscriptionController;
use KraftInk\Plugins\Shop\Http\Controllers\ProductAvailabilityController;

Route::prefix('shop')
    ->name('plugins.shop.public.')
    ->middleware('web')
    ->group(function () {
        Route::prefix('guest')
            ->name('guests.')
            ->group(function () {
                Route::get('/', [GuestController::class, 'index'])->middleware(NoIndex::class)->name('index');
                Route::post('/code', [GuestController::class, 'start'])
                    ->middleware('throttle:5,15')
                    ->name('code');
                Route::post('/verify', [GuestController::class, 'verify'])
                    ->middleware('throttle:10,15')
                    ->name('verify');
                Route::post('/logout', [GuestController::class, 'logout'])->name('logout');
                Route::get('/cancel', [GuestController::class, 'cancel'])->name('checkout.cancel');
                Route::post('/checkout', [GuestController::class, 'checkout'])
                    ->middleware(['permission:shop.view', 'throttle:10,1'])
                    ->name('checkout.store');
                Route::get('/orders', [GuestController::class, 'orders'])
                    ->middleware(NoIndex::class)
                    ->name('orders.index');
                Route::get('/orders/{reference}', [ShopController::class, 'showOrder'])
                    ->whereUuid('reference')
                    ->middleware(NoIndex::class)
                    ->name('orders.show');
                Route::get('/orders/{reference}/downloads/{id}', [ShopController::class, 'download'])
                    ->whereUuid('reference')
                    ->whereNumber('id')
                    ->name('orders.downloads.show');
                Route::post('/orders/{reference}/retry', [ShopController::class, 'retry'])
                    ->whereUuid('reference')
                    ->middleware('throttle:5,1')
                    ->name('orders.retry');
                Route::get('/paypal/{reference}/return', [ShopController::class, 'paypalReturn'])
                    ->whereUuid('reference')
                    ->middleware('throttle:10,1')
                    ->name('payments.paypal.return');
                Route::get('/receipts/{id}', [ReceiptController::class, 'download'])
                    ->whereNumber('id')
                    ->name('receipts.download');
            });
        Route::get('/paysafecard/{reference}/return', [PaysafecardController::class, 'returned'])
            ->whereUuid('reference')
            ->middleware('throttle:10,1')
            ->name('payments.paysafecard.return');
        Route::get('/giftcards/claim/{token}', [CardController::class, 'claim'])
            ->where('token', '[a-f0-9]{64}')
            ->middleware(['throttle:60,1', NoIndex::class])
            ->name('giftcards.claim');
        Route::middleware('permission:shop.view')->group(function () {
            Route::get('/', [ShopController::class, 'index'])
                ->middleware('capability:extension,shop')
                ->name('catalog.index');
            Route::get('/products/{id}/options/availability', ProductAvailabilityController::class)
                ->whereNumber('id')
                ->middleware('throttle:60,1')
                ->name('products.availability');
            Route::get('/products/{id}/{slug?}', [ShopController::class, 'product'])
                ->whereNumber('id')
                ->name('products.show');
            Route::get('/cart', [ShopController::class, 'cart'])->middleware(NoIndex::class)->name('cart.index');
            Route::post('/cart/{id}', [ShopController::class, 'updateCart'])
                ->whereNumber('id')
                ->middleware('throttle:60,1')
                ->name('cart.update');
            Route::post('/subscriptions/products/{id}', [SubscriptionController::class, 'prepare'])
                ->whereNumber('id')
                ->middleware('throttle:60,1')
                ->name('subscriptions.prepare');
        });
        Route::middleware('auth')->group(function () {
            Route::get('/subscriptions/checkout', [SubscriptionController::class, 'checkout'])
                ->middleware('permission:shop.view')
                ->name('subscriptions.checkout');
            Route::post('/subscriptions/checkout', [SubscriptionController::class, 'store'])
                ->middleware(['permission:shop.view', 'throttle:10,1'])
                ->name('subscriptions.store');
            Route::post('/checkout', [ShopController::class, 'checkout'])
                ->middleware(['permission:shop.view', 'throttle:10,1'])
                ->name('checkout.store');
            Route::get('/giftcards', [CardController::class, 'index'])
                ->middleware(NoIndex::class)
                ->name('giftcards.index');
            Route::post('/giftcards/{id}/email', [CardController::class, 'email'])
                ->whereNumber('id')
                ->middleware('throttle:5,1')
                ->name('giftcards.email');
            Route::post('/giftcards/claim/{token}', [CardController::class, 'redeem'])
                ->where('token', '[a-f0-9]{64}')
                ->middleware('throttle:30,1')
                ->name('giftcards.redeem');
            Route::get('/orders', [ShopController::class, 'orders'])->name('orders.index');
            Route::get('/gifts/{id}/download', [ShopController::class, 'downloadGift'])
                ->whereNumber('id')
                ->name('gifts.download');
            Route::get('/orders/{reference}', [ShopController::class, 'showOrder'])
                ->whereUuid('reference')
                ->name('orders.show');
            Route::get('/receipts/{id}', [ReceiptController::class, 'download'])
                ->whereNumber('id')
                ->name('receipts.download');
            Route::get('/orders/{reference}/downloads/{id}', [ShopController::class, 'download'])
                ->whereUuid('reference')
                ->whereNumber('id')
                ->name('orders.downloads.show');
            Route::post('/subscriptions/{id}/cancel', [ShopController::class, 'cancelSubscription'])
                ->whereNumber('id')
                ->middleware('throttle:5,1')
                ->name('subscriptions.cancel');
            Route::get('/subscriptions/{id}/change', [SubscriptionController::class, 'index'])
                ->whereNumber('id')
                ->middleware('permission:shop.view')
                ->name('subscriptions.change');
            Route::post('/subscriptions/{id}/payment', [SubscriptionController::class, 'payment'])
                ->whereNumber('id')
                ->middleware(['permission:shop.view', 'throttle:5,1'])
                ->name('subscriptions.payment');
            Route::post('/subscriptions/{id}/payment-method', [SubscriptionController::class, 'paymentMethod'])
                ->whereNumber('id')
                ->middleware(['permission:shop.view', 'throttle:5,1'])
                ->name('subscriptions.payment-method');
            Route::get('/subscriptions/{id}/payment-method/return', [
                SubscriptionController::class,
                'paymentMethodReturn',
            ])
                ->whereNumber('id')
                ->middleware(['permission:shop.view', 'throttle:10,1'])
                ->name('subscriptions.payment-method.return');
            Route::post('/subscriptions/{id}/change', [SubscriptionController::class, 'preview'])
                ->whereNumber('id')
                ->middleware(['permission:shop.view', 'throttle:10,1'])
                ->name('subscriptions.preview');
            Route::post('/subscriptions/{id}/changes/{change}', [SubscriptionController::class, 'confirm'])
                ->whereNumber('id')
                ->whereUuid('change')
                ->middleware(['permission:shop.view', 'throttle:5,1'])
                ->name('subscriptions.confirm');
            Route::post('/orders/{reference}/retry', [ShopController::class, 'retry'])
                ->whereUuid('reference')
                ->middleware('throttle:5,1')
                ->name('orders.retry');
            Route::get('/paypal/{reference}/return', [ShopController::class, 'paypalReturn'])
                ->whereUuid('reference')
                ->middleware('throttle:10,1')
                ->name('payments.paypal.return');
        });
    });
