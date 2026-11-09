<?php

namespace KraftInk\Plugins\Shop\Http\Controllers\Payments;

use Illuminate\Http\Request;
use KraftInk\Plugins\Shop\Services\Payments\PaymentwallService;
use Illuminate\Http\Response;

class PaymentwallController
{
    public function notification(Request $request, PaymentwallService $payments): Response
    {
        $payments->receive($request);

        return response('OK', 200)->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
