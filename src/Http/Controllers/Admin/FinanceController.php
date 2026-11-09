<?php

namespace KraftInk\Plugins\Shop\Http\Controllers\Admin;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\System\SensitiveAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use KraftInk\Plugins\Shop\Contracts\Payments\RefundsPayments;
use KraftInk\Plugins\Shop\Models\Orders\Order;
use KraftInk\Plugins\Shop\Services\Finance\CreditRecovery;
use KraftInk\Plugins\Shop\Services\Finance\RefundService;
use KraftInk\Plugins\Shop\Services\Orders\OrderService;
use KraftInk\Plugins\Shop\Services\Payments\GatewayRegistry;

class FinanceController
{
    public function retryFulfillment(Request $request, int $id, OrderService $orders): RedirectResponse
    {
        $request->validate(['confirm_retry' => 'accepted']);
        app(SensitiveAction::class)->confirm($request);
        $orders->retryFulfillment($id, $request->user());

        return back()->with('status', __('shop::messages.feedback.saved'));
    }

    public function reviewDispute(Request $request, int $id, RefundService $refunds): RedirectResponse
    {
        $data = $request->validate([
            'outcome' => 'required|in:won,lost',
            'note' => 'required|string|max:1000',
            'confirm_review' => 'accepted',
        ]);
        app(SensitiveAction::class)->confirm($request);
        $refunds->reviewDispute($id, $data['outcome'], $data['note'], $request->user());

        return back()->with('status', __('shop::messages.feedback.saved'));
    }

    public function recoverCredit(Request $request, int $id, CreditRecovery $recoveries): RedirectResponse
    {
        app(SensitiveAction::class)->confirm($request);
        $data = $request->validate([
            'amount_minor' => 'required|integer|min:1|max:9999999999',
            'request_key' => 'required|uuid',
            'confirm_recovery' => 'accepted',
        ]);
        $recoveries->recover($id, (int) $data['amount_minor'], $data['request_key'], $request->user());

        return back()->with('status', __('shop::commerce.wallet.recovered'));
    }

    public function refund(Request $request, int $id, RefundService $refunds): RedirectResponse
    {
        app(SensitiveAction::class)->confirm($request);
        $data = $request->validate([
            'amount_minor' => 'required|integer|min:1|max:999999999',
            'request_key' => 'required|uuid',
            'reason' => 'required|string|max:255',
            'confirm_effects' => 'accepted',
        ]);
        $refund = $refunds->request(
            $id,
            (int) $data['amount_minor'],
            $data['request_key'],
            $data['reason'],
            $request->user(),
        );

        return back()->with('status', __('shop::commerce.refunds.result_'.$refund->status));
    }

    public function refresh(Request $request, string $id, RefundService $refunds): RedirectResponse
    {
        app(SensitiveAction::class)->confirm($request);
        $refund = $refunds->process($id);

        return back()->with('status', __('shop::commerce.refunds.result_'.$refund->status));
    }

    public function reconcile(Request $request, string $id, RefundService $refunds): RedirectResponse
    {
        app(SensitiveAction::class)->confirm($request);
        $data = $request->validate([
            'provider_reference' => ['required', 'string', 'max:180', 'regex:/^[a-zA-Z0-9_:-]+$/D'],
        ]);
        $refund = $refunds->reconcile($id, $data['provider_reference']);

        return back()->with('status', __('shop::commerce.refunds.result_'.$refund->status));
    }

    public function payment(Request $request, int $id, GatewayRegistry $gateways): RedirectResponse
    {
        app(SensitiveAction::class)->confirm($request);
        $order = Order::query()->find($id);
        if (!($order && $order->paid_at)) {
            abort(404);
        }
        $gateway = $gateways->get($order->provider);
        if (!$gateway instanceof RefundsPayments) {
            throw new HttpException(422, __('shop::commerce.refunds.unsupported'));
        }
        /** @var RefundsPayments $gateway */
        $payment = $gateway->payment($order);
        if (!($payment->amount === $order->total_minor && $payment->currency === $order->currency)) {
            throw new HttpException(422, __('shop::commerce.refunds.payment_mismatch'));
        }
        Order::query()
            ->where('id', $id)
            ->update(['payment_reference' => $payment->id, 'payment_kind' => $payment->kind]);

        return back()->with('status', __('shop::commerce.refunds.payment_verified'));
    }
}
