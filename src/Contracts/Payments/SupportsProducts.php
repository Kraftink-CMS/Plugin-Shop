<?php

namespace KraftInk\Plugins\Shop\Contracts\Payments;

use Illuminate\Support\Collection;

interface SupportsProducts
{
    public function supportsProducts(Collection $products): bool;
}
