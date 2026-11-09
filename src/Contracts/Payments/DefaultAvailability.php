<?php

namespace KraftInk\Plugins\Shop\Contracts\Payments;

interface DefaultAvailability
{
    public function defaultEnabled(): bool;
}
