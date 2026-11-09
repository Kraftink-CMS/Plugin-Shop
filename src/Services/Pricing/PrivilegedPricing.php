<?php

namespace KraftInk\Plugins\Shop\Services\Pricing;

use App\Models\Accounts\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use KraftInk\Plugins\Shop\Models\Catalog\Category;
use KraftInk\Plugins\Shop\Models\Catalog\PrivilegedPrice;
use KraftInk\Plugins\Shop\Services\Finance\IssuedCreditScope;
use InvalidArgumentException;

class PrivilegedPricing
{
    public function quote(
        User $user,
        Collection $products,
        array $unitPrices,
        array $cart,
        array $couponShares = [],
    ): array {
        $roles = $user->effectiveRoles()->pluck('id')->all();
        /** @var Collection<int, PrivilegedPrice> $rules */
        $rules = PrivilegedPrice::query()
            ->whereIn('role_id', $roles)
            ->where('enabled', true)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderBy('id')
            ->get();
        $result = ['promotions' => [], 'coupon_shares' => [], 'privileged_shares' => [], 'snapshots' => []];
        $categories = [];
        foreach ($products as $product) {
            $id = $product->id;
            $quantity = (int) ($cart[$id] ?? 0);
            $unit = $unitPrices[$id] ?? null;
            if (!is_int($unit) || $unit < 0 || $quantity < 1 || $quantity > 99 || $unit * $quantity > 99999999) {
                throw new InvalidArgumentException('Invalid privileged pricing input.');
            }
            $gross = $unit * $quantity;
            $coupon = $couponShares[$id] ?? 0;
            if (!is_int($coupon) || $coupon < 0 || $coupon > $gross) {
                throw new InvalidArgumentException('Invalid coupon allocation.');
            }
            $best = $coupon;
            $selected = null;
            if ($product->kind !== 'giftcard' && !$product->wallet_credit_minor && !$product->billing_interval) {
                foreach ($rules as $rule) {
                    if ($rule->game !== $product->game || $rule->currency !== $product->currency) {
                        continue;
                    }
                    if ($rule->scope === 'category') {
                        $categories[$rule->category_id] ??= Category::descendantIds((int) $rule->category_id);
                    }
                    $matches = match ($rule->scope) {
                        'all' => true,
                        'product' => (int) $rule->product_id === $id,
                        'category' => in_array((int) $product->category_id, $categories[$rule->category_id], true),
                        default => false,
                    };
                    if (!$matches || $rule->amount < 1 || !in_array($rule->type, ['percent', 'fixed'], true)) {
                        continue;
                    }
                    try {
                        app(IssuedCreditScope::class)->assert($rule, [$product]);
                    } catch (ValidationException) {
                        continue;
                    }
                    $combined = $rule->combine_coupon ? $coupon : 0;
                    $base = $gross - $combined;
                    $discount =
                        $rule->type === 'percent'
                            ? intdiv($base * min(10000, (int) $rule->amount), 10000)
                            : min($base, (int) $rule->amount * $quantity);
                    if ($combined + $discount > $best) {
                        $best = $combined + $discount;
                        $selected = [
                            'id' => $rule->id,
                            'name' => $rule->name,
                            'role_id' => $rule->role_id,
                            'type' => $rule->type,
                            'amount' => (int) $rule->amount,
                            'combine_coupon' => (bool) $rule->combine_coupon,
                            'privileged_minor' => $discount,
                            'coupon_minor' => $combined,
                        ];
                    }
                }
            }
            $result['promotions'][$id] = $best;
            $result['coupon_shares'][$id] = $selected ? $selected['coupon_minor'] : $coupon;
            $result['privileged_shares'][$id] = $selected['privileged_minor'] ?? 0;
            $result['snapshots'][$id] = $selected;
        }

        return $result;
    }
}
