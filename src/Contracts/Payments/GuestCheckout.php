<?php

namespace KraftInk\Plugins\Shop\Contracts\Payments;

use KraftInk\Plugins\Shop\Data\Payments\CheckoutSession;

interface GuestCheckout
{
    public function createGuestCheckout(object $order, object $guest): CheckoutSession;
}
