<?php

namespace KraftInk\Plugins\Shop\Contracts\Payments;

interface CancelsSubscriptions
{
    public function cancelSubscription(object $subscription): void;
}
