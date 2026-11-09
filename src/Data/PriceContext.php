<?php

namespace KraftInk\Plugins\Shop\Data;

final readonly class PriceContext
{
    public function __construct(
        public int $beneficiaryId,
        public object $product,
        public int $targetMinor,
        public array $funding,
        public ?object $state,
    ) {
    }
}
