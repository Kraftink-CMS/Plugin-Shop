<?php

use Illuminate\Support\Facades\Route;
use KraftInk\Plugins\Shop\Http\Controllers\Admin\AdminController;
use KraftInk\Plugins\Shop\Http\Controllers\Admin\CommerceController;
use KraftInk\Plugins\Shop\Http\Controllers\Admin\FinanceController;
use KraftInk\Plugins\Shop\Http\Controllers\Admin\ManualController;
use KraftInk\Plugins\Shop\Http\Controllers\Admin\Pricing\GroupController;
use KraftInk\Plugins\Shop\Http\Controllers\Admin\Pricing\OrderController;
use KraftInk\Plugins\Shop\Http\Controllers\Admin\SalesController;
use KraftInk\Plugins\Shop\Http\Controllers\DeliveryController;
use KraftInk\Plugins\Shop\Http\Controllers\ReceiptController;
use KraftInk\Plugins\Shop\Http\Controllers\Admin\Pricing\PrivilegedController;
use KraftInk\Plugins\Shop\Http\Controllers\Admin\StorefrontController;
use KraftInk\Plugins\Shop\Services\Payments\WebhookSetup;

Route::prefix('admin/plugins/shop')
    ->name('plugins.shop.admin.')
    ->middleware(['web', 'auth', 'permission:admin.access', 'permission:shop.manage'])
    ->group(function () {
        Route::get('/privileged-prices', [PrivilegedController::class, 'index'])->name('privileged.index');
        Route::get('/privileged-prices/create', [PrivilegedController::class, 'edit'])->name('privileged.create');
        Route::get('/privileged-prices/{id}/edit', [PrivilegedController::class, 'edit'])
            ->whereNumber('id')
            ->name('privileged.edit');
        Route::post('/privileged-prices', [PrivilegedController::class, 'save'])->name('privileged.store');
        Route::put('/privileged-prices/{id}', [PrivilegedController::class, 'save'])
            ->whereNumber('id')
            ->name('privileged.update');
        Route::delete('/privileged-prices/{id}', [PrivilegedController::class, 'delete'])
            ->whereNumber('id')
            ->name('privileged.destroy');
        Route::get('/storefront', [StorefrontController::class, 'index'])->name('storefront.index');
        Route::post('/storefront', [StorefrontController::class, 'save'])->name('storefront.update');
        Route::get('/', [AdminController::class, 'index'])->name('catalog.index');
        foreach (['orders', 'categories', 'coupons', 'giftcards', 'payments'] as $section) {
            Route::get('/'.$section, [AdminController::class, 'index'])
                ->defaults('section', $section)
                ->name($section.'.index');
        }
        Route::get('/products/create', [AdminController::class, 'create'])->name('products.create');
        Route::get('/products/{id}/edit', [AdminController::class, 'edit'])
            ->whereNumber('id')
            ->name('products.edit');
        Route::get('/categories/create', [AdminController::class, 'create'])
            ->defaults('kind', 'category')
            ->name('categories.create');
        Route::get('/categories/{id}/edit', [AdminController::class, 'edit'])
            ->defaults('kind', 'category')
            ->whereNumber('id')
            ->name('categories.edit');
        Route::get('/coupons/create', [AdminController::class, 'create'])
            ->defaults('kind', 'coupon')
            ->name('coupons.create');
        Route::get('/coupons/{id}/edit', [AdminController::class, 'edit'])
            ->defaults('kind', 'coupon')
            ->whereNumber('id')
            ->name('coupons.edit');
        Route::post('/products', [AdminController::class, 'saveProduct'])->name('products.store');
        Route::put('/products/{id}', [AdminController::class, 'saveProduct'])
            ->whereNumber('id')
            ->name('products.update');
        Route::delete('/products/{id}', [AdminController::class, 'deleteProduct'])
            ->whereNumber('id')
            ->name('products.destroy');
        Route::post('/products/{id}/duplicate', [AdminController::class, 'duplicateProduct'])
            ->whereNumber('id')
            ->name('products.duplicate');
        Route::post('/products/{id}/codes', [AdminController::class, 'addCodes'])
            ->whereNumber('id')
            ->name('products.codes.store');
        Route::post('/categories', [AdminController::class, 'saveCategory'])->name('categories.store');
        Route::put('/categories/{id}', [AdminController::class, 'saveCategory'])
            ->whereNumber('id')
            ->name('categories.update');
        Route::delete('/categories/{id}', [AdminController::class, 'deleteCategory'])
            ->whereNumber('id')
            ->name('categories.destroy');
        Route::post('/coupons', [AdminController::class, 'saveCoupon'])->name('coupons.store');
        Route::put('/coupons/{id}', [AdminController::class, 'saveCoupon'])
            ->whereNumber('id')
            ->name('coupons.update');
        Route::post('/giftcards', [AdminController::class, 'giftcard'])->middleware('throttle:sensitive')->name('giftcards.store');
        Route::delete('/giftcards/{id}', [AdminController::class, 'revokeGiftcard'])
            ->whereNumber('id')
            ->middleware('throttle:sensitive')
            ->name('giftcards.destroy');
        Route::post('/manual/payments/{id}', [ManualController::class, 'payment'])
            ->whereNumber('id')
            ->middleware(['permission:shop.payments.record', 'throttle:sensitive'])
            ->name('payments.manual.store');
        Route::post('/manual/refunds/{id}', [ManualController::class, 'refund'])
            ->whereUuid('id')
            ->middleware(['permission:shop.payments.record', 'permission:shop.refunds.manage', 'throttle:sensitive'])
            ->name('refunds.manual.store');
        Route::post('/settings', [AdminController::class, 'settings'])
            ->middleware(['permission:shop.finance.configure', 'throttle:sensitive'])
            ->name('payments.settings.update');
        Route::post('/settings/{provider}/credentials', [AdminController::class, 'paymentCredentials'])
            ->whereIn('provider', array_keys(WebhookSetup::FIELDS))
            ->middleware(['permission:shop.finance.configure', 'throttle:shop.payment-setup'])
            ->name('payments.credentials.update');
        Route::post('/settings/{provider}/webhook', [AdminController::class, 'paymentWebhook'])
            ->whereIn('provider', array_keys(WebhookSetup::FIELDS))
            ->middleware(['permission:shop.finance.configure', 'throttle:shop.payment-setup'])
            ->name('payments.webhook.update');
        Route::get('/orders/{id}/pricing', [OrderController::class, 'show'])
            ->whereNumber('id')
            ->name('orders.pricing.show');
        Route::get('/orders/{reference}', [AdminController::class, 'order'])
            ->whereUuid('reference')
            ->name('orders.show');
        Route::get('/receipts/{id}', [ReceiptController::class, 'download'])
            ->whereNumber('id')
            ->name('receipts.download');
        Route::post('/receipts/{id}/email', [ReceiptController::class, 'email'])
            ->whereNumber('id')
            ->middleware('throttle:5,1')
            ->name('receipts.email');
        Route::get('/commerce', [CommerceController::class, 'index'])
            ->middleware('permission:shop.finance.configure')
            ->name('commerce.index');
        Route::post('/commerce/preview', [CommerceController::class, 'preview'])
            ->middleware(['permission:shop.finance.configure', 'throttle:30,1'])
            ->name('commerce.preview');
        Route::post('/upgrades/states/{id}/review', [GroupController::class, 'review'])
            ->whereNumber('id')
            ->middleware(['permission:shop.refunds.manage', 'throttle:sensitive'])
            ->name('pricing.states.review');
        Route::get('/upgrades', [GroupController::class, 'index'])->name('pricing.index');
        Route::post('/upgrades', [GroupController::class, 'save'])->name('pricing.store');
        Route::put('/upgrades/{id}', [GroupController::class, 'save'])
            ->whereNumber('id')
            ->name('pricing.update');
        Route::delete('/upgrades/{id}', [GroupController::class, 'delete'])
            ->whereNumber('id')
            ->name('pricing.destroy');
        Route::get('/reports', [SalesController::class, 'index'])->name('reports.index');
        Route::get('/reports/export', [SalesController::class, 'export'])->name('reports.export');
        Route::post('/commerce', [CommerceController::class, 'save'])
            ->middleware(['permission:shop.finance.configure', 'throttle:sensitive'])
            ->name('commerce.update');
        Route::post('/orders/{id}/receipt', [ReceiptController::class, 'historical'])
            ->whereNumber('id')
            ->middleware(['permission:shop.finance.configure', 'throttle:sensitive'])
            ->name('orders.receipts.store');
        Route::post('/orders/{id}/cancel', [AdminController::class, 'cancel'])
            ->whereNumber('id')
            ->name('orders.cancel');
        Route::middleware(['permission:shop.refunds.manage', 'throttle:sensitive'])->group(function () {
            Route::post('/orders/{id}/fulfillment/retry', [FinanceController::class, 'retryFulfillment'])
                ->whereNumber('id')
                ->name('orders.fulfillment.retry');
            Route::post('/orders/{id}/refund', [FinanceController::class, 'refund'])
                ->whereNumber('id')
                ->name('refunds.store');
            Route::post('/orders/{id}/payment', [FinanceController::class, 'payment'])
                ->whereNumber('id')
                ->name('orders.payments.refresh');
            Route::post('/refunds/{id}/refresh', [FinanceController::class, 'refresh'])
                ->whereUuid('id')
                ->name('refunds.refresh');
            Route::post('/refunds/{id}/reconcile', [FinanceController::class, 'reconcile'])
                ->whereUuid('id')
                ->name('refunds.reconcile');
            Route::post('/credit-recoveries/{id}', [FinanceController::class, 'recoverCredit'])
                ->middleware('permission:wallet.manage')
                ->whereNumber('id')
                ->name('credit_recoveries.store');
            Route::post('/disputes/{id}/review', [FinanceController::class, 'reviewDispute'])
                ->whereNumber('id')
                ->name('disputes.review');
        });

        Route::prefix('deliveries')
            ->name('deliveries.')
            ->group(function () {
                Route::post('/{id}/retry', [DeliveryController::class, 'retry'])
                    ->whereUuid('id')
                    ->middleware('throttle:sensitive')
                    ->name('retry');
                Route::post('/{id}/cancel', [DeliveryController::class, 'cancel'])
                    ->whereUuid('id')
                    ->middleware('throttle:sensitive')
                    ->name('cancel');
            });
    });
