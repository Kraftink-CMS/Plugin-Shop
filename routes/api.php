<?php

use Illuminate\Support\Facades\Route;
use KraftInk\Plugins\Shop\Http\Controllers\Payments\PaymentwallController;
use KraftInk\Plugins\Shop\Http\Controllers\Payments\PaysafecardController;
use KraftInk\Plugins\Shop\Http\Controllers\ShopController;

Route::prefix('shop/webhooks')
    ->name('plugins.shop.webhooks.')
    ->group(function () {
        Route::post('/paysafecard/{reference}', [PaysafecardController::class, 'notification'])
            ->whereUuid('reference')
            ->middleware('throttle:60,1')
            ->name('paysafecard.handle');
        Route::match(['get', 'post'], '/paymentwall', [PaymentwallController::class, 'notification'])
            ->middleware('throttle:180,1')
            ->name('paymentwall.handle');
        Route::post('/stripe', [ShopController::class, 'stripeWebhook'])
            ->middleware('throttle:180,1')
            ->name('stripe.handle');
        Route::post('/paypal', [ShopController::class, 'paypalWebhook'])
            ->middleware('throttle:180,1')
            ->name('paypal.handle');
    });
