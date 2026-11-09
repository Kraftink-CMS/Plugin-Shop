<?php

namespace KraftInk\Plugins\Shop\Contracts\Payments;

use Illuminate\Http\Request;

interface VerifiesWebhooks
{
    public function verifyWebhook(Request $request): array;
}
