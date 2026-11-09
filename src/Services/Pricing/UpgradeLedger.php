<?php

namespace KraftInk\Plugins\Shop\Services\Pricing;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Support\Collection;
use App\Kraft\Audit;
use App\Kraft\Games\GameRegistry;
use App\Models\Accounts\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use KraftInk\Plugins\Shop\Data\PriceContext;
use KraftInk\Plugins\Shop\Models\Delivery\Delivery;
use KraftInk\Plugins\Shop\Models\Orders\Order;
use KraftInk\Plugins\Shop\Models\Orders\OrderItem;
use KraftInk\Plugins\Shop\Models\Orders\RoleGrant;
use KraftInk\Plugins\Shop\Models\Pricing\UpgradeAllocation;
use KraftInk\Plugins\Shop\Models\Pricing\UpgradeGroup;
use KraftInk\Plugins\Shop\Models\Pricing\UpgradeState;
use KraftInk\Plugins\Shop\Services\Roles\RolePolicy;
use InvalidArgumentException;

class UpgradeLedger
{
    public function quote(int $beneficiary, object $product, int $target): ?array
    {
        if (!$product->upgrade_group) {
            return null;
        }
        $game = $product->game ?: app(GameRegistry::class)->activeId();
        $group = UpgradeGroup::query()
            ->where('slug', $product->upgrade_group)
            ->where('game', $game)
            ->where('currency', $product->currency)
            ->sharedLock()
            ->first();
        $this->check($group && $group->enabled, 'group_unavailable');
        $state = $this->state($beneficiary, $group);
        $this->check(!$state->pending_order_id, 'pending');
        $expired = $state->expires_at && now()->gte($state->expires_at);
        $this->check(!$expired || $group->expired_policy === 'reset', 'expired');
        $this->check($state->status !== 'review', 'review');
        $funding = [];
        if ($state->current_item_id) {
            $current = $state->currentItem;
            $this->check(
                !$current->role_id ||
                    RoleGrant::query()
                        ->where('order_id', $current->order_id)
                        ->where('user_id', $beneficiary)
                        ->where('role_id', $current->role_id)
                        ->whereNull('revoked_at')
                        ->exists(),
                'review',
            );
            if (!$expired) {
                $this->check($product->upgrade_weight > $state->weight, 'tier_owned');
                $funding = $this->funding($current);
            }
        }
        $context = new PriceContext($beneficiary, $product, $target, $funding, $state);
        try {
            $credit = app(PricingRegistry::class)->get($group->strategy)->credit($context);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['checkout' => __('shop::commerce.upgrades.group_unavailable')]);
        }
        $this->check(
            $credit >= 0 && $credit <= $target && $credit <= array_sum(array_column($funding, 'available_minor')),
            'strategy_invalid',
        );
        $remaining = $credit;
        foreach ($funding as &$source) {
            $source['credited_minor'] = min($remaining, $source['available_minor']);
            $remaining -= $source['credited_minor'];
        }

        return [
            'schema' => 1,
            'strategy' => $group->strategy,
            'group_id' => $group->id,
            'state_id' => $state->id,
            'state_version' => $state->version,
            'previous_item_id' => $state->current_item_id,
            'beneficiary_id' => $beneficiary,
            'game' => $game,
            'currency' => $product->currency,
            'weight' => $product->upgrade_weight,
            'expired_reset' => (bool) $expired,
            'target_minor' => $target,
            'credit_minor' => $credit,
            'paid_minor' => $target - $credit,
            'sources' => $funding,
        ];
    }

    public function reserve(int $orderId, array $snapshot): void
    {
        $updated = UpgradeState::query()
            ->where('id', $snapshot['state_id'])
            ->where('version', $snapshot['state_version'])
            ->whereNull('pending_order_id')
            ->update(['pending_order_id' => $orderId, 'updated_at' => now()]);
        $this->check($updated === 1, 'pending');
    }

    public function release(int $orderId): void
    {
        UpgradeState::query()
            ->where('pending_order_id', $orderId)
            ->update(['pending_order_id' => null, 'updated_at' => now()]);
    }

    public function activate(object $order, object $item): void
    {
        if (!$item->pricing_snapshot) {
            return;
        }
        $snapshot = $item->pricing_snapshot;
        $state = UpgradeState::query()->where('id', $snapshot['state_id'])->lockForUpdate()->first();
        $this->check(
            $state &&
                $state->user_id === $snapshot['beneficiary_id'] &&
                $state->pending_order_id === $order->id &&
                $state->version === $snapshot['state_version'],
            'review',
        );
        $prior = $state->currentItem;
        if ($prior?->role_id) {
            if (
                RoleGrant::query()
                    ->where('order_id', $prior->order_id)
                    ->where('user_id', $state->user_id)
                    ->where('role_id', $prior->role_id)
                    ->whereNotNull('revoked_at')
                    ->exists()
            ) {
                Order::query()
                    ->where('id', $order->id)
                    ->update(['pricing_review_reason' => 'revoked_access', 'financial_review' => true]);
            }
            RoleGrant::query()
                ->where('order_id', $prior->order_id)
                ->where('user_id', $state->user_id)
                ->where('role_id', $prior->role_id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'revocation_reason' => 'superseded']);
        }
        foreach ($snapshot['sources'] as $source) {
            UpgradeAllocation::query()->insertOrIgnore([
                'target_item_id' => $item->id,
                'source_item_id' => $source['item_id'],
                'retained_minor' => $source['available_minor'],
                'credited_minor' => $source['credited_minor'],
                'created_at' => now(),
            ]);
        }
        UpgradeState::query()
            ->where('id', $state->id)
            ->update([
                'current_item_id' => $item->id,
                'pending_order_id' => null,
                'version' => $state->version + 1,
                'weight' => $item->upgrade_weight,
                'expires_at' => $item->role_days ? now()->addDays($item->role_days) : null,
                'status' => 'active',
                'updated_at' => now(),
            ]);
        Audit::record('shop.upgrade.activated', [
            'order_id' => $order->id,
            'item_id' => $item->id,
            'group_id' => $snapshot['group_id'],
            'beneficiary_id' => $state->user_id,
            'state_version' => $state->version + 1,
            'strategy' => $snapshot['strategy'],
            'target_minor' => $snapshot['target_minor'],
            'credit_minor' => $snapshot['credit_minor'],
            'paid_minor' => $snapshot['paid_minor'],
        ]);
    }

    public function reconcileOrder(int $orderId): void
    {
        $sourceIds = OrderItem::query()->where('order_id', $orderId)->pluck('id');
        $targets = UpgradeAllocation::query()->whereIn('source_item_id', $sourceIds)->pluck('target_item_id');
        $targetOrders = OrderItem::query()
            ->whereIn('id', $targets->merge($sourceIds))
            ->whereNotNull('pricing_snapshot')
            ->distinct()
            ->orderBy('order_id')
            ->pluck('order_id');
        foreach ($targetOrders as $targetOrderId) {
            $target = Order::query()->where('id', $targetOrderId)->lockForUpdate()->first();
            /** @var Collection<int, OrderItem> $items */
            $items = $target->items()->whereNotNull('pricing_snapshot')->with('upgradeAllocations')->get();
            $valid = !$target->pricing_review_reason;
            foreach ($items as $item) {
                /** @var Collection<int, UpgradeAllocation> $allocations */
                $allocations = $item->upgradeAllocations;
                $net = $this->netAmounts($allocations->pluck('source_item_id')->all());
                foreach ($allocations as $allocation) {
                    if ($allocation->credited_minor > ($net[$allocation->source_item_id] ?? 0)) {
                        $valid = false;
                    }
                }
            }
            if ((bool) $target->pricing_hold !== !$valid) {
                Order::query()
                    ->where('id', $targetOrderId)
                    ->update([
                        'pricing_hold' => !$valid,
                        'financial_review' => $target->financial_review || !$valid,
                        'updated_at' => now(),
                    ]);
                Audit::record($valid ? 'shop.upgrade.funding_restored' : 'shop.upgrade.funding_hold', [
                    'order_id' => $targetOrderId,
                    'source_order_id' => $orderId,
                ]);
            }
            UpgradeState::query()
                ->whereIn('current_item_id', $items->pluck('id'))
                ->update([
                    'status' => $valid && $target->status === 'paid' && !$target->financial_hold && !$target->giftcard_hold
                            ? 'active'
                            : 'review',
                    'updated_at' => now(),
                ]);
        }
        foreach (
            UpgradeState::query()
                ->whereIn('current_item_id', $sourceIds)
                ->whereNull('pending_order_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get() as $state
        ) {
            $this->restorePrevious($state);
        }
    }

    public function netAmounts(array $ids, bool $lock = false): array
    {
        if ($ids === []) {
            return [];
        }
        $items = OrderItem::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get()
            ->keyBy('id');
        /** @var Collection<int, Order> $orders */
        $orders = Order::query()
            ->whereIn('id', $items->pluck('order_id')->unique())
            ->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get()
            ->keyBy('id');
        /** @var Collection<int, Collection<int, OrderItem>> $siblings */
        $siblings = OrderItem::query()
            ->whereIn('order_id', $orders->keys())
            ->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get()
            ->groupBy('order_id');
        $result = [];
        foreach ($orders as $order) {
            $weights = $siblings[$order->id]->pluck('paid_minor', 'id')->all();
            $refunds = $order->isUsable() && !in_array(null, $weights, true)
                ? Allocation::proportional(min((int) $order->refunded_minor, array_sum($weights)), $weights)
                : [];
            foreach ($weights as $id => $paid) {
                if ($items->has($id)) {
                    $result[$id] = isset($refunds[$id]) ? $paid - $refunds[$id] : 0;
                }
            }
        }

        return $result;
    }

    public function review(int $stateId, string $decision, string $note, User $actor): void
    {
        if (!($actor->hasPermission('shop.refunds.manage') && in_array($decision, ['restore', 'reset'], true))) {
            throw new HttpException(403);
        }
        DB::transaction(function () use ($stateId, $decision, $note, $actor) {
            $state = UpgradeState::query()->where('id', $stateId)->lockForUpdate()->first();
            if ($state === null) {
                abort(404);
            }
            $group = UpgradeGroup::query()->find($state->group_id);
            if (!($group->game === app(GameRegistry::class)->activeId() && !$state->pending_order_id)) {
                throw new HttpException(422);
            }
            $item = $state->currentItem;
            if ($item) {
                app(RolePolicy::class)->assertManage($actor, $item->role_id);
            }
            if ($decision === 'reset') {
                if ($item?->role_id) {
                    RoleGrant::query()
                        ->where('order_id', $item->order_id)
                        ->where('user_id', $state->user_id)
                        ->where('role_id', $item->role_id)
                        ->whereNull('revoked_at')
                        ->update(['revoked_at' => now(), 'revocation_reason' => 'review_reset']);
                }
                if ($item) {
                    Delivery::query()
                        ->where('order_item_id', $item->id)
                        ->where('kind', 'grant')
                        ->whereIn('status', ['pending', 'processing'])
                        ->update(['status' => 'cancelled', 'last_error' => 'upgrade_review_reset']);
                }
                UpgradeState::query()
                    ->where('id', $stateId)
                    ->update([
                        'current_item_id' => null,
                        'weight' => 0,
                        'version' => $state->version + 1,
                        'expires_at' => null,
                        'status' => 'active',
                        'updated_at' => now(),
                    ]);
            } else {
                if ($item === null) {
                    throw new HttpException(422);
                }
                $order = Order::query()->where('id', $item->order_id)->lockForUpdate()->first();
                $user = User::query()->findOrFail($state->user_id);
                if (
                    !(
                        $order->status === 'paid' &&
                        !$order->financial_hold &&
                        !$order->giftcard_hold &&
                        !$user->anonymized_at &&
                        !$user->isBanned() &&
                        (!$state->expires_at || now()->lt($state->expires_at))
                    )
                ) {
                    throw new HttpException(422, __('shop::commerce.upgrades.review'));
                }
                /** @var Collection<int, UpgradeAllocation> $allocations */
                $allocations = $item->upgradeAllocations;
                $net = $this->netAmounts($allocations->pluck('source_item_id')->all());
                foreach ($allocations as $allocation) {
                    if (!($allocation->credited_minor <= ($net[$allocation->source_item_id] ?? 0))) {
                        throw new HttpException(422, __('shop::commerce.upgrades.funding_hold'));
                    }
                }
                Order::query()
                    ->where('id', $order->id)
                    ->update(['pricing_review_reason' => null]);
                if ($item->role_id) {
                    $order
                        ->roleGrants()
                        ->where('user_id', $state->user_id)
                        ->where('role_id', $item->role_id)
                        ->update(['revoked_at' => null, 'revocation_reason' => null]);
                }
                UpgradeState::query()
                    ->where('id', $stateId)
                    ->update(['status' => 'active', 'updated_at' => now()]);
                $this->reconcileOrder($order->id);
            }
            Audit::record(
                'shop.upgrade.reviewed',
                ['state_id' => $stateId, 'decision' => $decision, 'note' => $note, 'beneficiary_id' => $state->user_id],
                $actor,
            );
        }, 3);
    }

    private function state(int $userId, object $group): UpgradeState
    {
        $state = UpgradeState::query()
            ->where('user_id', $userId)
            ->where('group_id', $group->id)
            ->lockForUpdate()
            ->first();
        if ($state) {
            return $state;
        }
        $missingState = OrderItem::query()
            ->from('shop__order_items as items')
            ->join('shop__orders as orders', 'orders.id', '=', 'items.order_id')
            ->whereRaw('COALESCE(items.recipient_id, orders.user_id) = ?', [$userId])
            ->where('items.upgrade_group', $group->slug)
            ->where('orders.currency', $group->currency)
            ->where('items.game', $group->game)
            ->where('orders.status', 'paid')
            ->exists();
        $this->check(!$missingState, 'review');
        $id = UpgradeState::query()->insertGetId([
            'user_id' => $userId,
            'group_id' => $group->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return UpgradeState::query()->find($id);
    }

    private function funding(OrderItem $item): array
    {
        $this->check($item->paid_minor !== null, 'review');
        $retained = $item
            ->upgradeAllocations()
            ->orderBy('source_item_id')
            ->pluck('retained_minor', 'source_item_id')
            ->all();
        $retained[$item->id] = $item->paid_minor;
        $this->check(count($retained) <= 1000, 'history_bound');
        $net = $this->netAmounts(array_keys($retained));
        $funding = [];
        foreach ($retained as $id => $amount) {
            $available = min($amount, $net[$id] ?? 0);
            if ($available > 0) {
                $funding[] = ['item_id' => $id, 'available_minor' => $available];
            }
        }

        return $funding;
    }

    private function check(bool $condition, string $key): void
    {
        if (!$condition) {
            throw ValidationException::withMessages(['checkout' => __('shop::commerce.upgrades.'.$key)]);
        }
    }

    private function restorePrevious(UpgradeState $state): void
    {
        $item = $state->currentItem;
        $order = $item->order;
        if (!in_array($order->status, ['refunded', 'charged_back'], true)) {
            return;
        }
        $seen = [];
        while ($item && count($seen) < 1000) {
            $snapshot = $item->pricing_snapshot ?? [];
            $id = $snapshot['previous_item_id'] ?? null;
            if (!$id || in_array($id, $seen, true)) {
                break;
            }
            $seen[] = $id;
            $item = OrderItem::query()->find($id);
            if (!$item) {
                break;
            }
            $order = $item->order;
            $grant = $item->role_id
                ? $order->roleGrants()
                    ->where('user_id', $state->user_id)
                    ->where('role_id', $item->role_id)
                    ->first()
                : null;
            if (
                !$order->isUsable() ||
                ($grant &&
                    ($grant->revocation_reason !== 'superseded' ||
                        ($grant->expires_at && now()->gte($grant->expires_at))))
            ) {
                continue;
            }
            if ($item->role_id && !$grant) {
                continue;
            }
            if ($grant) {
                RoleGrant::query()
                    ->where('id', $grant->id)
                    ->update(['revoked_at' => null, 'revocation_reason' => null]);
            }
            UpgradeState::query()
                ->where('id', $state->id)
                ->update([
                    'current_item_id' => $item->id,
                    'weight' => $item->upgrade_weight,
                    'version' => $state->version + 1,
                    'expires_at' => $grant?->expires_at,
                    'status' => 'active',
                    'updated_at' => now(),
                ]);
            Audit::record('shop.upgrade.previous_restored', ['state_id' => $state->id, 'item_id' => $item->id]);

            return;
        }
        UpgradeState::query()
            ->where('id', $state->id)
            ->update(['status' => 'review', 'updated_at' => now()]);
    }
}
