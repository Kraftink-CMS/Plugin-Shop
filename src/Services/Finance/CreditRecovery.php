<?php

namespace KraftInk\Plugins\Shop\Services\Finance;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\Audit;
use App\Kraft\Wallet\Wallet;
use App\Models\Accounts\User;
use App\Models\Wallet\WalletTransaction;
use Illuminate\Support\Facades\DB;
use KraftInk\Plugins\Shop\Models\Billing\CreditRecovery as ShopCreditRecovery;

class CreditRecovery
{
    public function record(object $order, object $item): bool
    {
        if (
            !$item->wallet_credit_minor ||
            !WalletTransaction::query()
                ->where('operation_key', 'shop:credit:'.$item->id)
                ->exists()
        ) {
            return false;
        }
        ShopCreditRecovery::query()->insertOrIgnore([
            'order_item_id' => $item->id,
            'order_id' => $order->id,
            'user_id' => $item->recipient_id ?? $order->user_id,
            'amount_minor' => $item->wallet_credit_minor * $item->quantity,
            'currency' => $item->wallet_currency,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }

    public function recover(int $id, int $amount, string $key, User $actor): void
    {
        if (!($actor->hasPermission('wallet.manage') && $actor->hasPermission('shop.refunds.manage'))) {
            throw new HttpException(403);
        }
        DB::transaction(function () use ($id, $amount, $key, $actor) {
            $recovery = ShopCreditRecovery::query()->where('id', $id)->lockForUpdate()->first();
            if ($recovery === null) {
                abort(404);
            }
            $wallet = app(Wallet::class);
            if ($recovery->currency !== $wallet->currency()) {
                throw new HttpException(422, __('shop::commerce.wallet.incompatible'));
            }
            $operation = 'shop:credit-recovery:'.$key;
            $existing = WalletTransaction::query()->where('operation_key', $operation)->first();
            if (!($existing || ($amount > 0 && $amount <= $recovery->amount_minor - $recovery->recovered_minor))) {
                throw new HttpException(422, __('shop::commerce.refunds.amount_exceeded'));
            }
            $wallet->debit(
                $recovery->user_id,
                $amount,
                $operation,
                'shop.credit_recovery',
                ['recovery_id' => $id],
                $actor,
            );
            if (!$existing) {
                ShopCreditRecovery::query()
                    ->where('id', $id)
                    ->increment('recovered_minor', $amount, ['updated_at' => now()]);
                Audit::record('shop.credit.recovered', ['id' => $id, 'amount_minor' => $amount], $actor);
            }
        }, 3);
    }
}
