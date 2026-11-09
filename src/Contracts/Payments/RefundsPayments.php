<?php

namespace KraftInk\Plugins\Shop\Contracts\Payments;

use KraftInk\Plugins\Shop\Data\Payments\PaymentReference;
use KraftInk\Plugins\Shop\Data\Payments\RefundResult;

interface RefundsPayments
{
    public function payment(object $order): PaymentReference;

    public function refund(PaymentReference $payment, object $refund): RefundResult;

    public function getRefund(string $reference): RefundResult;
}
