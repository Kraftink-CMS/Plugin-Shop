<?php

namespace KraftInk\Plugins\Shop\Listeners\Link;

use App\Events\CollectingLinkDeliverySources;
use App\Kraft\Games\GameRegistry;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use KraftInk\Plugins\Shop\Models\Delivery\Delivery;

class DeliveryMonitor
{
    public function collect(CollectingLinkDeliverySources $event): void
    {
        $event->add(
            'shop',
            __('shop::messages.title'),
            'shop.manage',
            fn (): Builder => Delivery::query()
                ->toBase()
                ->join('shop__orders', 'shop__orders.id', '=', 'shop__deliveries.order_id')
                ->where('shop__deliveries.game', app(GameRegistry::class)->activeId())
                ->select([
                    'shop__deliveries.id',
                    'shop__deliveries.game',
                    'shop__deliveries.server_id',
                    'shop__deliveries.player_name',
                    'shop__deliveries.player_uuid',
                    'shop__deliveries.command',
                    'shop__deliveries.status',
                    'shop__deliveries.attempts',
                    'shop__deliveries.requires_online',
                    'shop__deliveries.lease_expires_at',
                    'shop__deliveries.last_error',
                    'shop__deliveries.created_at',
                    'shop__deliveries.delivered_at',
                    'shop__orders.reference AS reference',
                ]),
            fn (object $row): array => $this->details($row),
            actionable: fn (Collection $rows): array => Delivery::deliverable()
                ->whereKey($rows->pluck('id')->all())
                ->where('game', app(GameRegistry::class)->activeId())
                ->pluck('id')
                ->all(),
        );
    }

    private function details(object $row): array
    {
        return [
            'reference' => __('shop::messages.site.orders.order').' #'.Str::limit($row->reference, 8, '…'),
            'url' => route('plugins.shop.admin.orders.show', $row->reference),
            'retry_url' => $row->actionable ? route('plugins.shop.admin.deliveries.retry', $row->id) : null,
            'cancel_url' => $row->actionable && $row->status === 'pending' && !$row->delivered_at
                ? route('plugins.shop.admin.deliveries.cancel', $row->id)
                : null,
        ];
    }
}
