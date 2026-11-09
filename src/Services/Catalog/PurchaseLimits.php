<?php

namespace KraftInk\Plugins\Shop\Services\Catalog;

use App\Models\Accounts\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use KraftInk\Plugins\Shop\Models\Catalog\Category;
use KraftInk\Plugins\Shop\Models\Orders\OrderItem;
use InvalidArgumentException;

class PurchaseLimits
{
    public function assert(
        ?User $user,
        object $product,
        int $quantity,
        Collection $cart,
        ?int $guestId = null,
        array $replacedOrders = [],
    ): void {
        $query = OrderItem::query()
            ->join('shop__orders', 'shop__orders.id', '=', 'shop__order_items.order_id')
            ->whereIn('shop__orders.status', ['pending', 'paid'])
            ->when($replacedOrders, fn ($query) => $query->whereNotIn('shop__orders.id', $replacedOrders))
            ->where('product_id', $product->id);
        $recurring = !empty($product->billing_interval);
        $start = $this->start($recurring ? null : $product->limit_period);
        if ($start) {
            $query->where('shop__orders.created_at', '>=', $start);
        }
        if (
            !$recurring &&
            $product->limit_per_user !== null &&
            (int) (clone $query)
                ->when(
                    $user,
                    fn ($query) => $query->whereRaw(
                        'COALESCE(shop__order_items.recipient_id, shop__orders.user_id) = ?',
                        [$user->id],
                    ),
                    fn ($query) => $query->where('shop__orders.guest_id', $guestId),
                )
                ->sum('quantity') +
                $quantity >
                $product->limit_per_user
        ) {
            throw ValidationException::withMessages(['checkout' => __('shop::messages.errors.invalid_limit')]);
        }
        if (
            !$recurring &&
            $product->global_limit !== null &&
            (int) $query->sum('quantity') + $quantity > $product->global_limit
        ) {
            throw ValidationException::withMessages(['checkout' => __('shop::messages.products.options.global_limit')]);
        }
        $parent = $product->category_id;
        for ($depth = 0; $parent && $depth < 20; $depth++) {
            $category = Category::query()->find($parent);
            if (!$category) {
                break;
            }
            if ($category->exclusive) {
                $ids = Category::descendantIds((int) $category->id);
                if (
                    $cart->contains(
                        fn ($other) => $other->id !== $product->id && in_array($other->category_id, $ids, true),
                    ) ||
                    OrderItem::query()
                        ->join('shop__orders', 'shop__orders.id', '=', 'shop__order_items.order_id')
                        ->join('shop__products', 'shop__products.id', '=', 'shop__order_items.product_id')
                        ->when(
                            $user,
                            fn ($query) => $query->whereRaw(
                                'COALESCE(shop__order_items.recipient_id, shop__orders.user_id) = ?',
                                [$user->id],
                            ),
                            fn ($query) => $query->where('shop__orders.guest_id', $guestId),
                        )
                        ->whereIn('shop__orders.status', ['pending', 'paid'])
                        ->when($replacedOrders, fn ($query) => $query->whereNotIn('shop__orders.id', $replacedOrders))
                        ->whereIn(DB::raw('COALESCE(shop__order_items.category_id, shop__products.category_id)'), $ids)
                        ->where('shop__order_items.product_id', '!=', $product->id)
                        ->exists()
                ) {
                    throw ValidationException::withMessages([
                        'checkout' => __('shop::messages.products.options.exclusive_category'),
                    ]);
                }
            }
            $parent = $category->parent_id;
        }
    }

    public function start(?string $period): ?CarbonImmutable
    {
        return match ($period) {
            null => null,
            'day' => CarbonImmutable::now('UTC')->startOfDay(),
            'week' => CarbonImmutable::now('UTC')->startOfWeek(CarbonInterface::MONDAY),
            'month' => CarbonImmutable::now('UTC')->startOfMonth(),
            'year' => CarbonImmutable::now('UTC')->startOfYear(),
            default => throw new InvalidArgumentException('Invalid purchase limit period.'),
        };
    }
}
