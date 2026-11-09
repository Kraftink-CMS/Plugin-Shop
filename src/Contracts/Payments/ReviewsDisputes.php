<?php

namespace KraftInk\Plugins\Shop\Contracts\Payments;

use KraftInk\Plugins\Shop\Data\Payments\DisputeResult;

interface ReviewsDisputes
{
    public function getDispute(string $reference): DisputeResult;
}
