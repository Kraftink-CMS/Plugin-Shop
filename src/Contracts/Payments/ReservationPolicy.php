<?php

namespace KraftInk\Plugins\Shop\Contracts\Payments;

interface ReservationPolicy
{
    public function reservationSeconds(): int;
}
