<?php

namespace KraftInk\Plugins\Shop\Http\Controllers\Admin;

use App\Kraft\System\SensitiveAction;
use App\Kraft\Wallet\Money;
use Illuminate\Http\Request;
use KraftInk\Plugins\Shop\Services\Payments\ManualPayments;
use Illuminate\Http\RedirectResponse;

class ManualController
{
    public function payment(Request $request, int $id, ManualPayments $payments): RedirectResponse
    {
        $data = $this->validate($request);
        $payments->payment(
            $id,
            Money::parse($data['amount']),
            $data['currency'],
            $data['request_key'],
            $data['reference'],
            $data['note'],
            $request->user(),
        );

        return back()->with('status', __('shop::commerce.payments.manual.recorded'));
    }

    public function refund(Request $request, string $id, ManualPayments $payments): RedirectResponse
    {
        $data = $this->validate($request);
        $payments->refund(
            $id,
            Money::parse($data['amount']),
            $data['currency'],
            $data['request_key'],
            $data['reference'],
            $data['note'],
            $request->user(),
        );

        return back()->with('status', __('shop::commerce.payments.manual.recorded'));
    }

    private function validate(Request $request): array
    {
        $data = $request->validate([
            'amount' => ['required', 'string', 'max:30'],
            'currency' => ['required', 'string', 'size:3'],
            'request_key' => ['required', 'uuid'],
            'reference' => ['required', 'string', 'max:120'],
            'note' => ['required', 'string', 'max:1000'],
            'confirm_funds' => ['accepted'],
        ]);
        app(SensitiveAction::class)->confirm($request);

        return $data;
    }
}
