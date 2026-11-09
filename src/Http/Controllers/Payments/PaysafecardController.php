<?php

namespace KraftInk\Plugins\Shop\Http\Controllers\Payments;

use Illuminate\Http\Request;
use KraftInk\Plugins\Shop\Models\Orders\Order;
use KraftInk\Plugins\Shop\Services\Payments\PaysafecardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class PaysafecardController
{
    public function notification(string $reference, PaysafecardService $payments): JsonResponse
    {
        $payments->settle($reference);

        return response()->json(['received' => true]);
    }

    public function returned(Request $request, string $reference, PaysafecardService $payments): RedirectResponse
    {
        $payments->settle($reference);

        $guest = Order::query()->where('reference', $reference)->value('guest_id');

        return redirect()->route(
            $guest ? 'plugins.shop.public.guests.orders.show' : 'plugins.shop.public.orders.show',
            $reference,
        );
    }
}
