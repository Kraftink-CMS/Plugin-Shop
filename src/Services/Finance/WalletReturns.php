<?php

namespace KraftInk\Plugins\Shop\Services\Finance;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\Audit;
use App\Kraft\System\OperationLock;
use App\Kraft\Wallet\Wallet;
use App\Models\Accounts\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use KraftInk\Plugins\Shop\Models\Billing\WalletReturn;
use KraftInk\Plugins\Shop\Models\Orders\Order;
use Throwable;

class WalletReturns
{
    public function queue(object $order, int $amount, string $source, ?string $refundId = null): void
    {
        if ($amount === 0) {
            return;
        }
        if (!($amount > 0)) {
            throw new HttpException(422);
        }
        DB::transaction(function () use ($order, $amount, $source, $refundId) {
            WalletReturn::query()->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'source_key' => $source,
                'order_id' => $order->id,
                'refund_id' => $refundId,
                'user_id' => $order->user_id,
                'amount_minor' => $amount,
                'currency' => $order->currency,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $return = WalletReturn::query()->where('source_key', $source)->first();
            if (!($return && $return->order_id === $order->id && $return->amount_minor === $amount && $return->currency === $order->currency && $return->user_id === $order->user_id && $return->refund_id === $refundId)) {
                throw new HttpException(409);
            }
            DB::afterCommit(fn () => $this->process($return->id));
        }, 3);
    }

    public function process(string $id): void
    {
        $lease = app(OperationLock::class)->acquireShared();
        try {
            $return = WalletReturn::query()->find($id);
            if (!$return || $return->status === 'succeeded') {
                return;
            }
            try {
                DB::transaction(function () use ($return) {
                    User::query()->whereKey($return->user_id)->lockForUpdate()->firstOrFail();
                    $return = WalletReturn::query()->where('id', $return->id)->lockForUpdate()->first();
                    if ($return->status === 'succeeded') {
                        return;
                    }
                    if ($return->currency !== app(Wallet::class)->currency()) {
                        throw new HttpException(422);
                    }
                    $transaction = app(Wallet::class)->credit(
                        $return->user_id,
                        $return->amount_minor,
                        'shop:wallet:return:'.$return->id,
                        'shop.refund',
                        [
                            'order_id' => $return->order_id,
                            'wallet_return_id' => $return->id,
                            'refund_id' => $return->refund_id,
                        ],
                    );
                    WalletReturn::query()
                        ->where('id', $return->id)
                        ->update([
                            'status' => 'succeeded',
                            'transaction_reference' => $transaction->id,
                            'error' => null,
                            'updated_at' => now(),
                        ]);
                    Audit::record('shop.wallet.returned', ['id' => $return->id, 'order_id' => $return->order_id]);
                }, 3);
            } catch (Throwable) {
                WalletReturn::query()
                    ->where('id', $id)
                    ->where('status', 'pending')
                    ->update(['error' => 'account_unavailable', 'updated_at' => now()]);
                Order::query()
                    ->where('id', $return->order_id)
                    ->update(['financial_review' => true]);
            }
        } finally {
            $lease->release();
        }
    }

    public function retryPending(): void
    {
        WalletReturn::query()
            ->where('status', 'pending')
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit(100)
            ->pluck('id')
            ->each(fn ($id) => $this->process($id));
    }

    public function pendingForOrder(int $orderId): int
    {
        return (int) WalletReturn::query()
            ->where('order_id', $orderId)
            ->where('status', 'pending')
            ->sum('amount_minor');
    }

    public function retryForRefund(string $refundId): void
    {
        WalletReturn::query()
            ->where('refund_id', $refundId)
            ->where('status', 'pending')
            ->pluck('id')
            ->each(fn ($id) => $this->process($id));
    }
}
