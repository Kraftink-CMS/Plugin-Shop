<?php

namespace KraftInk\Plugins\Shop\Contracts\Payments;

use App\Kraft\Currencies\Currency;

interface SupportsCurrencies
{
    public function supportsCurrency(Currency $currency): bool;
}
