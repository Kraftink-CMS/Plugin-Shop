<?php

namespace KraftInk\Plugins\Shop\Http\Controllers;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Http\Request;
use KraftInk\Plugins\Shop\Models\Delivery\Delivery;
use Illuminate\Http\RedirectResponse;
use App\Kraft\Games\GameRegistry;
use App\Kraft\Link\DeliveryQueue;
use App\Kraft\System\SensitiveAction;
use App\Kraft\Audit;
use Illuminate\Support\Facades\DB;

class DeliveryController
{
    private const string PLUGIN = 'shop';

    public function retry(Request $request, string $id): RedirectResponse
    {
        $request->validate(['confirm_retry' => ['accepted']]);
        app(SensitiveAction::class)->confirm($request);
        $updated = Delivery::deliverable()
            ->whereKey($id)
            ->where('game', app(GameRegistry::class)->activeId())
            ->whereRaw(...DeliveryQueue::attentionCondition())
            ->update(DeliveryQueue::retryValues());
        if (!$updated) {
            throw new HttpException(409, __('shop::integrations.delivery.retry_unavailable'));
        }

        return back()->with('status', __('shop::integrations.delivery.retried'));
    }

    public function cancel(Request $request, string $id): RedirectResponse
    {
        $request->validate(['confirm_cancel' => ['accepted']]);
        app(SensitiveAction::class)->confirm($request);
        DB::transaction(function () use ($id): void {
            $delivery = Delivery::deliverable()
                ->whereKey($id)
                ->where('game', app(GameRegistry::class)->activeId())
                ->lockForUpdate()
                ->first();
            if (!$delivery || $delivery->status !== 'pending' || $delivery->delivered_at) {
                throw new HttpException(409, __('admin.servers.deliveries.cancel_unavailable'));
            }
            $delivery->update(['status' => 'cancelled']);
            Audit::record('link.delivery_cancelled', ['source' => self::PLUGIN, 'id' => $id]);
        }, 3);

        return back()->with('status', __('admin.servers.deliveries.cancelled'));
    }
}
