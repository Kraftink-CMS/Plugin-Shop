<?php

namespace KraftInk\Plugins\Shop\Contracts;

use KraftInk\Plugins\Shop\Data\PriceContext;

interface UpgradePricing
{
    public function id(): string;

    public function label(): string;

    public function credit(PriceContext $context): int;
}
