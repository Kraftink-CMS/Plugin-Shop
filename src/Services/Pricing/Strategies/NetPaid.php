<?php

namespace KraftInk\Plugins\Shop\Services\Pricing\Strategies;

use KraftInk\Plugins\Shop\Contracts\UpgradePricing;
use KraftInk\Plugins\Shop\Data\PriceContext;

class NetPaid implements UpgradePricing
{
    public function id(): string
    {
        return 'net-paid';
    }

    public function label(): string
    {
        return __('shop::commerce.upgrades.net_paid');
    }

    public function credit(PriceContext $context): int
    {
        return min($context->targetMinor, array_sum(array_column($context->funding, 'available_minor')));
    }
}
