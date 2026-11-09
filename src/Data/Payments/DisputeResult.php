<?php

namespace KraftInk\Plugins\Shop\Data\Payments;

class DisputeResult
{
    public function __construct(
        public readonly string $id,
        public readonly string $paymentReference,
        public readonly int $amount,
        public readonly string $currency,
        public readonly string $status,
    ) {
    }
}
