<?php

namespace KraftInk\Plugins\Shop\View\Composers;

use App\Kraft\Settings;
use App\Kraft\SiteDates;
use App\Kraft\Wallet\Money;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use KraftInk\Plugins\Shop\Models\Billing\Refund;
use KraftInk\Plugins\Shop\Models\Catalog\Product;
use KraftInk\Plugins\Shop\Models\Orders\Order;
use KraftInk\Plugins\Shop\Services\Catalog\Catalog;
use KraftInk\Plugins\Shop\Services\Payments\GatewayRegistry;

class PublicSales
{
    public function compose(View $view): void
    {
        $view->with('publicSales', $this->data());
    }

    public function data(): array
    {
        $settings = app(Settings::class);
        $goal = null;
        if ($settings->get('shop.storefront.goal_enabled', false)) {
            $currency = $settings->get('shop.storefront.goal_currency', 'EUR');
            $target = (int) $settings->get('shop.storefront.goal_minor', 0);
            if ($target > 0) {
                $month = CarbonImmutable::now(app(SiteDates::class)->timezone())->startOfMonth();
                $internal = ['wallet', 'giftcard', 'legacy'];
                foreach (app(GatewayRegistry::class)->all() as $gateway) {
                    if (array_intersect(['internal_balance', 'zero_total'], $gateway->capabilities())) {
                        $internal[] = $gateway->id();
                    }
                }
                $refunds = Refund::query()
                    ->where('status', 'succeeded')
                    ->groupBy('order_id')
                    ->select('order_id')
                    ->selectRaw('SUM(external_minor) AS refunded_minor');
                $amount = (int) Order::query()
                    ->from('shop__orders as orders')
                    ->leftJoinSub($refunds, 'refunds', 'refunds.order_id', '=', 'orders.id')
                    ->whereNotNull('orders.paid_at')
                    ->where('orders.status', 'paid')
                    ->where('orders.financial_hold', false)
                    ->where('orders.pricing_hold', false)
                    ->where('orders.giftcard_hold', false)
                    ->where('orders.currency', $currency)
                    ->whereNotIn('orders.provider', array_unique($internal))
                    ->where('orders.paid_at', '>=', $month->utc())
                    ->where('orders.paid_at', '<', $month->addMonth()->utc())
                    ->selectRaw('COALESCE(SUM(orders.total_minor - COALESCE(refunds.refunded_minor, 0)), 0) AS amount')
                    ->value('amount');
                $goal = [
                    'title' => $settings->get('shop.storefront.goal_title') ?: __('shop::admin.storefront.goal'),
                    'amount' => Money::format(max(0, $amount)),
                    'target' => Money::format($target),
                    'currency' => $currency,
                    'percent' => min(100, intdiv(max(0, $amount) * 100, $target)),
                ];
            }
        }
        $recent = collect();
        if ($settings->get('shop.storefront.recent_enabled', false)) {
            $recent = Product::query()
                ->visibleTo(null)
                ->join('shop__order_items as items', 'shop__products.id', '=', 'items.product_id')
                ->join('shop__orders as orders', 'orders.id', '=', 'items.order_id')
                ->whereNotNull('orders.paid_at')
                ->where('orders.status', 'paid')
                ->where('orders.financial_hold', false)
                ->where('orders.pricing_hold', false)
                ->where('orders.giftcard_hold', false)
                ->where('orders.refunded_minor', 0)
                ->where('orders.provider', '!=', 'legacy')
                ->whereNotNull('items.paid_minor')
                ->orderByDesc('orders.paid_at')
                ->orderByDesc('items.id')
                ->limit(max(1, min(20, (int) $settings->get('shop.storefront.recent_limit', 5))))
                ->select(
                    'shop__products.*',
                    'items.quantity',
                    'items.paid_minor as purchased_minor',
                    'orders.currency as purchased_currency',
                    'orders.paid_at as purchased_at',
                )
                ->get()
                ->map(function (Product $product) {
                    $localized = app(Catalog::class)->localize($product);

                    return [
                        'name' => $localized->name,
                        'url' => route('plugins.shop.public.products.show', [$product->id, $product->slug]),
                        'quantity' => $product->getAttribute('quantity'),
                        'amount' => Money::format($product->getAttribute('purchased_minor')),
                        'currency' => $product->getAttribute('purchased_currency'),
                        'date' => app(SiteDates::class)->format($product->getAttribute('purchased_at')),
                    ];
                });
        }

        return [
            'goal' => $goal,
            'recent' => $recent,
            'show_amounts' => (bool) $settings->get('shop.storefront.recent_amounts', false),
        ];
    }
}
