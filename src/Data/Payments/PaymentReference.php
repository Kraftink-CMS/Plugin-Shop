<?php

namespace KraftInk\Plugins\Shop\Data\Payments;

class PaymentReference
{
    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public readonly int $amount,
        public readonly string $currency,
    ) {
    }
}
