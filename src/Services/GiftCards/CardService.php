<?php

namespace KraftInk\Plugins\Shop\Services\GiftCards;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\Audit;
use App\Kraft\System\OperationLock;
use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use KraftInk\Plugins\Shop\Models\Billing\Refund;
use KraftInk\Plugins\Shop\Models\GiftCards\GiftCard;
use KraftInk\Plugins\Shop\Models\GiftCards\GiftCardHold;
use KraftInk\Plugins\Shop\Models\GiftCards\GiftCardIssuance;
use KraftInk\Plugins\Shop\Models\Orders\Order;
use KraftInk\Plugins\Shop\Models\Orders\OrderItem;
use KraftInk\Plugins\Shop\Services\Pricing\Allocation;
use KraftInk\Plugins\Shop\Services\Pricing\UpgradeLedger;

class CardService
{
    public function issue(Order $order, OrderItem $item): void
    {
        $operationLease = app(OperationLock::class)->acquireShared();
        try {
            if ($item->kind !== 'giftcard') {
                return;
            }
            if (!($item->paid_minor >= $item->quantity)) {
                throw new HttpException(422, __('shop::commerce.giftcards.positive_value'));
            }
            for ($unit = 0; $unit < $item->quantity; $unit++) {
                if ($item->giftCardIssuances()->where('unit_number', $unit)->exists()) {
                    continue;
                }
                $value =
                    intdiv($item->paid_minor, $item->quantity) + ($unit < $item->paid_minor % $item->quantity ? 1 : 0);
                $code = strtoupper(bin2hex(random_bytes(16)));
                $claim = bin2hex(random_bytes(32));
                $id = GiftCard::query()->insertGetId([
                    'code_hash' => hash('sha256', $code),
                    'last_four' => substr($code, -4),
                    'secret' => Crypt::encryptString($code),
                    'claim_token_hash' => hash('sha256', $claim),
                    'claim_secret' => Crypt::encryptString($claim),
                    'balance_minor' => 0,
                    'currency' => $order->currency,
                    'recipient_id' => $item->giftcard_recipient_id,
                    'source_item_id' => $item->id,
                    'expires_at' => $item->giftcard_days ? now()->addDays($item->giftcard_days) : null,
                    'enabled' => false,
                    'created_at' => now(),
                ]);
                $item->giftCardIssuances()->create([
                    'unit_number' => $unit,
                    'giftcard_id' => $id,
                    'face_minor' => $value,
                    'created_at' => now(),
                ]);
                Audit::record('shop.giftcard.issued', [
                    'id' => $id,
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'value_minor' => $value,
                    'currency' => $order->currency,
                ]);
            }
        } finally {
            $operationLease->release();
        }
    }

    public function findClaim(string $token): object
    {
        $card = $this->claimQuery($token)->first();
        if ($card === null) {
            abort(404);
        }

        return $card;
    }

    public function claim(string $token, User $user): object
    {
        $operationLease = app(OperationLock::class)->acquireShared();
        try {
            return DB::transaction(function () use ($token, $user) {
                $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                if ($user->isBanned() || $user->anonymized_at || $user->import_locked) {
                    throw new HttpException(403);
                }
                $card = $this->claimQuery($token)->lockForUpdate()->first();
                if (!($card && (!$card->recipient_id || $card->recipient_id === $user->id))) {
                    abort(404);
                }
                if ($card->owner_id) {
                    if ($card->owner_id !== $user->id) {
                        abort(404);
                    }

                    return $card;
                }
                if (!$card->isSpendable()) {
                    throw new HttpException(422, __('shop::commerce.giftcards.unavailable'));
                }
                GiftCard::query()
                    ->where('id', $card->id)
                    ->whereNull('owner_id')
                    ->update(['owner_id' => $user->id, 'claimed_at' => now(), 'claim_secret' => null]);
                Audit::record('shop.giftcard.claimed', ['id' => $card->id], $user);

                return GiftCard::query()->find($card->id);
            }, 3);
        } finally {
            $operationLease->release();
        }
    }

    public function reconcileOrder(int $orderId): void
    {
        $operationLease = app(OperationLock::class)->acquireShared();
        try {
            DB::transaction(function () use ($orderId) {
                $itemIds = OrderItem::query()->where('order_id', $orderId)->pluck('id');
                $issuances = GiftCardIssuance::query()
                    ->whereIn('order_item_id', $itemIds)
                    ->orderBy('giftcard_id')
                    ->get();
                $cards = GiftCard::query()
                    ->whereIn('id', $issuances->pluck('giftcard_id'))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
                $net = app(UpgradeLedger::class)->netAmounts($issuances->pluck('order_item_id')->unique()->all());
                foreach ($issuances->groupBy('order_item_id') as $itemId => $units) {
                    $weights = $units->pluck('face_minor', 'giftcard_id')->all();
                    $values = Allocation::proportional(min($net[$itemId] ?? 0, array_sum($weights)), $weights);
                    foreach ($units as $unit) {
                        $this->applyValue($cards[$unit->giftcard_id], $values[$unit->giftcard_id]);
                    }
                }
                $used = Order::query()->where('id', $orderId)->value('giftcard_id');
                if ($used && !$cards->has($used)) {
                    $this->reconcileCard($used);
                }
            }, 3);
        } finally {
            $operationLease->release();
        }
    }

    public function reconcileCard(int $id): void
    {
        $operationLease = app(OperationLock::class)->acquireShared();
        try {
            DB::transaction(function () use ($id) {
                $card = GiftCard::query()->where('id', $id)->lockForUpdate()->first();
                if (!$card?->source_item_id) {
                    return;
                }
                $issuances = GiftCardIssuance::query()
                    ->where('order_item_id', $card->source_item_id)
                    ->orderBy('unit_number')
                    ->get();
                if (!$issuances->contains('giftcard_id', $id)) {
                    throw new HttpException(409);
                }
                $net = app(UpgradeLedger::class)->netAmounts([$card->source_item_id]);
                $weights = $issuances->pluck('face_minor', 'giftcard_id')->all();
                $valid = Allocation::proportional(min($net[$card->source_item_id] ?? 0, array_sum($weights)), $weights)[
                    $id
                ];
                $this->applyValue($card, $valid);
            }, 3);
        } finally {
            $operationLease->release();
        }
    }

    private function claimQuery(string $token): Builder
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            abort(404);
        }

        return GiftCard::query()->where('claim_token_hash', hash('sha256', $token));
    }

    private function applyValue(GiftCard $card, int $valid): void
    {
        $id = $card->id;
        $returned = Refund::query()
            ->where('status', 'succeeded')
            ->groupBy('order_id')
            ->select('order_id')
            ->selectRaw('SUM(giftcard_minor) AS returned_minor');
        $spends = Order::query()
            ->from('shop__orders as orders')
            ->leftJoinSub($returned, 'returned', 'returned.order_id', '=', 'orders.id')
            ->where('orders.giftcard_id', $id)
            ->where('orders.giftcard_minor', '>', 0)
            ->whereIn('orders.status', ['pending', 'paid', 'refunded', 'charged_back']);
        $used = (int) (clone $spends)
            ->selectRaw('COALESCE(SUM(orders.giftcard_minor - COALESCE(returned.returned_minor, 0)), 0) AS used_minor')
            ->value('used_minor');
        $overdrawn = $used > $valid;
        GiftCard::query()
            ->where('id', $id)
            ->update(['balance_minor' => max(0, $valid - $used), 'enabled' => $valid > 0 && !$card->revoked_at]);
        if (!$overdrawn) {
            $card->holds()->where('active', true)->update(['active' => false, 'updated_at' => now()]);
        }
        (clone $spends)
            ->select('orders.id', 'orders.giftcard_hold', 'orders.giftcard_minor')
            ->orderBy('orders.id')
            ->chunkById(
                200,
                function ($orders) use ($card, $id, $overdrawn) {
                    if ($overdrawn) {
                        $card->holds()->upsert(
                            $orders
                                ->map(
                                    fn ($order) => [
                                        'order_id' => $order->id,
                                        'active' => true,
                                        'created_at' => now(),
                                        'updated_at' => now(),
                                    ],
                                )
                                ->all(),
                            ['giftcard_id', 'order_id'],
                            ['active', 'updated_at'],
                        );
                    }
                    foreach ($orders as $order) {
                        $held = GiftCardHold::query()->where('order_id', $order->id)->where('active', true)->exists();
                        if ((bool) $order->giftcard_hold === $held) {
                            continue;
                        }
                        Order::query()
                            ->where('id', $order->id)
                            ->update(['giftcard_hold' => $held, 'financial_review' => true, 'updated_at' => now()]);
                        app(UpgradeLedger::class)->reconcileOrder($order->id);
                        Audit::record($held ? 'shop.giftcard.funding_hold' : 'shop.giftcard.funding_restored', [
                            'order_id' => $order->id,
                            'giftcard_id' => $id,
                        ]);
                    }
                },
                'orders.id',
                'id',
            );
    }
}
