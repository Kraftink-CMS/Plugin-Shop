<?php

namespace KraftInk\Plugins\Shop\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use KraftInk\Plugins\Shop\Models\Catalog\Product;
use KraftInk\Plugins\Shop\Services\Catalog\ProductOptions;
use KraftInk\Plugins\Shop\Services\Catalog\PurchaseLimits;
use KraftInk\Plugins\Shop\Services\Orders\SubscriptionService;
use KraftInk\Plugins\Shop\Services\Orders\SubscriptionTrials;

class ProductAvailabilityController
{
    public function __invoke(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'recipient' => ['nullable', 'string', 'max:64'],
            'quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'intent' => ['required', Rule::in(['add', 'set'])],
        ]);
        $product = Product::query()->visibleTo($request->user())->findOrFail($id);
        $quantity = (int) $data['quantity'];
        if (!$product->billing_interval && $data['intent'] === 'add') {
            $quantity += (int) $request->session()->get('shop.cart.'.$id, 0);
        }
        $canPurchase = true;
        $canTrial = false;
        $reason = null;
        $trialReason = null;
        try {
            if ($quantity > $product->quantityLimit()) {
                throw ValidationException::withMessages([
                    'quantity' => __(
                        $product->quantityLimit() === 0
                            ? 'shop::messages.products.options.unavailable'
                            : 'shop::messages.products.options.cart_limit',
                    ),
                ]);
            }
            $recipient = app(ProductOptions::class)->beneficiary($request->user(), [
                'recipient' => trim($data['recipient'] ?? ''),
            ]);
            if ($recipient) {
                if (!Product::query()->visibleTo($recipient)->whereKey($product->id)->exists()) {
                    throw ValidationException::withMessages([
                        'recipient' => __('shop::messages.errors.invalid_permission'),
                    ]);
                }
                if (!app(SubscriptionService::class)->canSubscribe($recipient, $product)) {
                    throw ValidationException::withMessages([
                        'recipient' => __('shop::subscriptions.already_subscribed'),
                    ]);
                }
                app(PurchaseLimits::class)->assert($recipient, $product, $quantity, collect([$product]));
                $canTrial = app(SubscriptionTrials::class)->eligible($request->user(), $recipient, $product);
            } else {
                $canTrial = $product->trial_days > 0 && app(SubscriptionTrials::class)->reversible($product);
            }
            if (!$canTrial && $product->trial_days > 0) {
                $trialReason = __('shop::subscriptions.trial_ineligible');
            }
        } catch (ValidationException $exception) {
            $canPurchase = false;
            $reason = $exception->validator->errors()->first();
        }

        return response()
            ->json([
                'canPurchase' => $canPurchase,
                'canTrial' => $canPurchase && $canTrial,
                'reason' => $reason,
                'trialReason' => $trialReason,
            ])
            ->header('Cache-Control', 'private, no-store');
    }
}
