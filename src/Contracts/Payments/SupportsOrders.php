<?php

namespace KraftInk\Plugins\Shop\Contracts\Payments;

interface SupportsOrders
{
    public function supports(object $order): bool;
}
