<?php

namespace KraftInk\Plugins\Shop\Listeners\Link;

use App\Events\AcknowledgingLinkCommand;
use App\Events\CollectingLinkCommands;
use App\Kraft\Link\DeliveryQueue;
use KraftInk\Plugins\Shop\Models\Delivery\Delivery;
use KraftInk\Plugins\Shop\Models\Orders\Order;

class DeliveryListener
{
    public function __construct(private DeliveryQueue $queue)
    {
    }

    public function collect(CollectingLinkCommands $event): void
    {
        $this->queue->collect($event, 'shop', Delivery::deliverable());
    }

    public function acknowledge(AcknowledgingLinkCommand $event): void
    {
        $this->queue->acknowledge($event, 'shop', Delivery::query(), function (
            Delivery $delivery,
            string $state,
        ): void {
            if (in_array($state, ['executed', 'uncertain'], true)) {
                Order::query()
                    ->whereKey($delivery->order_id)
                    ->update(['financial_review' => true]);
                Delivery::query()
                    ->whereKey($delivery->id)
                    ->update(['last_error' => 'confirmation_after_reversal:'.$state]);
            }
        });
    }
}
