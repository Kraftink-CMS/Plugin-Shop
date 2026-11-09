<?php

namespace KraftInk\Plugins\Shop\Http\Controllers;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\Audit;
use App\Kraft\System\SensitiveAction;
use Illuminate\Http\Request;
use KraftInk\Plugins\Shop\Models\Billing\Receipt;
use KraftInk\Plugins\Shop\Models\Orders\Order;
use KraftInk\Plugins\Shop\Services\Commerce\DocumentMessages;
use KraftInk\Plugins\Shop\Services\Commerce\ReceiptService;
use Illuminate\Http\Response;
use Illuminate\Http\RedirectResponse;
use KraftInk\Plugins\Shop\Services\GuestAuth;

class ReceiptController
{
    public function download(Request $request, int $id): Response
    {
        $receipt = Receipt::query()->find($id);
        if ($receipt === null) {
            abort(404);
        }
        $order = Order::query()->find($receipt->order_id);
        if (!($order && $order->hasValidBuyer() && ($request->routeIs('plugins.shop.admin.receipts.download') ? $request->user()->hasPermission('shop.manage') : ($request->routeIs('plugins.shop.public.guests.*') ? !$order->user_id && $order->guest_id === app(GuestAuth::class)->guest($request->session()->get('shop.guest_token'))?->id : !$order->guest_id && $order->user_id === $request->user()?->id)))) {
            abort(404);
        }
        if (!hash_equals($receipt->body_hash, hash('sha256', $receipt->body))) {
            throw new HttpException(409, __('shop::commerce.documents.integrity_failed'));
        }
        $filename = preg_replace('/[^a-zA-Z0-9_-]/', '-', $receipt->number).'.html';

        return response($receipt->body, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; sandbox",
        ]);
    }

    public function historical(Request $request, int $id, ReceiptService $receipts): RedirectResponse
    {
        $request->validate(['confirm_historical' => ['accepted']]);
        app(SensitiveAction::class)->confirm($request);
        $receipt = $receipts->payment($id);
        Audit::record('shop.receipt.historical_created', ['id' => $receipt->id, 'order_id' => $id], $request->user());

        return back()->with('status', __('shop::commerce.documents.created'));
    }

    public function email(Request $request, int $id, DocumentMessages $messages): RedirectResponse
    {
        $request->validate(['confirm_resend' => ['sometimes', 'accepted'], 'billing_page' => ['sometimes', 'boolean']]);
        $messages->request($id, $request->user(), $request->boolean('confirm_resend'));

        return ($request->boolean('billing_page')
            ? redirect()->to(route('plugins.shop.admin.commerce.index').'#billing-sending')
            : back()
        )->with('status', __('shop::commerce.documents.mail.queued'));
    }
}
