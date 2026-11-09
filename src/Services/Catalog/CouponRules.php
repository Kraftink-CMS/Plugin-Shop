<?php

namespace KraftInk\Plugins\Shop\Services\Catalog;

use App\Models\Accounts\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use KraftInk\Plugins\Shop\Models\Catalog\Category;
use KraftInk\Plugins\Shop\Models\Catalog\Coupon;
use KraftInk\Plugins\Shop\Models\Catalog\Product;
use KraftInk\Plugins\Shop\Services\Finance\IssuedCreditScope;
use KraftInk\Plugins\Shop\Services\Pricing\Allocation;

class CouponRules
{
    public function lockForCheckout(
        ?string $code,
        int $subtotal,
        string $currency,
        ?User $user,
        ?int $guestId,
    ): ?Coupon {
        if (!$code) {
            return null;
        }
        $coupon = Coupon::query()->where('code', strtoupper(trim($code)))->lockForUpdate()->first();
        if (
            !$coupon ||
            !$coupon->enabled ||
            ($coupon->starts_at && now()->lt($coupon->starts_at)) ||
            ($coupon->expires_at && now()->gte($coupon->expires_at)) ||
            $subtotal < $coupon->minimum_minor ||
            ($coupon->type !== 'percent' && $coupon->currency !== $currency)
        ) {
            throw $this->invalidCoupon();
        }
        $this->assertUsage($coupon, $user, $guestId);

        return $coupon;
    }

    public function discountShares(Coupon $coupon, Collection $products, array $prices, array $cart): array
    {
        $eligible = $this->eligible($coupon, $products);
        app(IssuedCreditScope::class)->assert($coupon, $eligible);
        $weights = [];
        foreach ($eligible as $product) {
            $weights[$product->id] = $prices[$product->id] * $cart[$product->id];
        }
        $eligibleSubtotal = array_sum($weights);
        if ($eligibleSubtotal < 1) {
            throw ValidationException::withMessages(['coupon' => __('shop::messages.products.options.coupon_scope')]);
        }
        $discount = min(
            $eligibleSubtotal,
            $coupon->type === 'percent'
                ? intdiv($eligibleSubtotal * min(10000, $coupon->amount), 10000)
                : $coupon->amount,
        );

        return Allocation::proportional($discount, $weights);
    }

    public function baselineUses(Coupon $coupon, ?int $user = null, ?CarbonInterface $start = null): int
    {
        $query = $coupon->usageBaselines();
        if ($user !== null) {
            $query->where('user_id', $user);
        }
        if ($start !== null) {
            $query->where(fn ($scope) => $scope->whereNull('occurred_at')->orWhere('occurred_at', '>=', $start));
        }

        return (int) $query->sum('uses');
    }

    /**
     * @template TKey of array-key
     *
     * @param  Collection<TKey, Product>  $products
     * @return Collection<TKey, Product>
     */
    public function eligible(Coupon $coupon, Collection $products): Collection
    {
        $ids = $coupon->product_ids ?? [];
        $categories = [];
        foreach ($coupon->category_ids ?? [] as $category) {
            $categories = array_merge($categories, Category::descendantIds((int) $category));
        }

        return $products->filter(
            fn ($product) => (!$ids && !$categories) ||
                in_array($product->id, $ids, true) ||
                in_array($product->category_id, $categories, true),
        );
    }

    private function assertUsage(Coupon $coupon, ?User $user, ?int $guestId): void
    {
        $usesQuery = $coupon->orders()->whereIn('status', ['pending', 'paid']);
        $period = app(PurchaseLimits::class)->start($coupon->period);
        if ($period) {
            $usesQuery->where('created_at', '>=', $period);
        }
        $uses = (clone $usesQuery)->count() + $this->baselineUses($coupon, start: $period);
        if (
            ($coupon->max_uses !== null && $uses >= $coupon->max_uses) ||
            ($coupon->role_id && !$user?->effectiveRoles()->contains('id', $coupon->role_id))
        ) {
            throw $this->invalidCoupon();
        }
        $accountUses = $usesQuery->where($user ? 'user_id' : 'guest_id', $user?->id ?? $guestId)->count() +
            ($user ? $this->baselineUses($coupon, $user->id, $period) : 0);
        if ($coupon->per_user_limit !== null && $accountUses >= $coupon->per_user_limit) {
            throw $this->invalidCoupon();
        }
    }

    private function invalidCoupon(): ValidationException
    {
        return ValidationException::withMessages(['checkout' => __('shop::messages.errors.invalid_coupon')]);
    }
}
