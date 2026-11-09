<?php

namespace KraftInk\Plugins\Shop\Http\Controllers\Admin\Pricing;

use Illuminate\Support\Collection;
use App\Kraft\System\TableQuery;
use Illuminate\Http\Request;
use KraftInk\Plugins\Shop\Models\Orders\Order;
use KraftInk\Plugins\Shop\Models\Orders\OrderItem;
use KraftInk\Plugins\Shop\Models\Pricing\UpgradeAllocation;
use KraftInk\Plugins\Shop\Services\Pricing\UpgradeLedger;
use Illuminate\Http\Response;

class OrderController
{
    public function show(Request $request, int $id): Response
    {
        $data = $request->validate(['item' => 'nullable|integer']);
        $order = Order::query()->find($id);
        if ($order === null) {
            abort(404);
        }
        /** @var Collection<int, OrderItem> $items */
        $items = OrderItem::query()->where('order_id', $id)->whereNotNull('pricing_snapshot')->orderBy('id')->get();
        $item = isset($data['item']) ? $items->firstWhere('id', $data['item']) : $items->first();
        if ($item === null) {
            abort(404);
        }
        $snapshot = $item->pricing_snapshot ?? [];
        $sources = TableQuery::paginate(
            UpgradeAllocation::query()
                ->from('shop__upgrade_allocations as allocations')
                ->join('shop__order_items as items', 'items.id', '=', 'allocations.source_item_id')
                ->join('shop__orders as orders', 'orders.id', '=', 'items.order_id')
                ->where('allocations.target_item_id', $item->id)
                ->orderBy('allocations.source_item_id')
                ->select(
                    'allocations.*',
                    'items.name',
                    'orders.reference',
                    'orders.id as order_id',
                    'orders.status',
                    'orders.currency',
                    'orders.paid_at',
                ),
            ['reference' => 'orders.reference', 'name' => 'items.name'],
            pageName: 'page',
            search: ['orders.reference', 'items.name'],
        )->withQueryString();
        $net = app(UpgradeLedger::class)->netAmounts($sources->getCollection()->pluck('source_item_id')->all());

        return response()
            ->view('shop::admin.pricing.show', [
                'order' => $order,
                'items' => $items,
                'item' => $item,
                'snapshot' => $snapshot,
                'sources' => $sources,
                'net' => $net,
            ])
            ->header('Cache-Control', 'no-store, private');
    }
}
