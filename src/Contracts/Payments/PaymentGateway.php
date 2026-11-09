<?php

namespace KraftInk\Plugins\Shop\Contracts\Payments;

use App\Models\Accounts\User;
use KraftInk\Plugins\Shop\Data\Payments\CheckoutSession;

interface PaymentGateway
{
    public function id(): string;

    public function name(): string;

    public function capabilities(): array;

    public function configurationFields(): array;

    public function configured(): bool;

    public function createCheckout(object $order, User $user): CheckoutSession;
}
