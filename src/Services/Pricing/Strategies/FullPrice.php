<?php

namespace KraftInk\Plugins\Shop\Services\Pricing\Strategies;

use KraftInk\Plugins\Shop\Contracts\UpgradePricing;
use KraftInk\Plugins\Shop\Data\PriceContext;

class FullPrice implements UpgradePricing
{
    public function id(): string
    {
        return 'full-price';
    }

    public function label(): string
    {
        return __('shop::commerce.upgrades.full_price');
    }

    public function credit(PriceContext $context): int
    {
        return 0;
    }
}
