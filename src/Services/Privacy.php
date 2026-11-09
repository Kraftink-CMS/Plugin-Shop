<?php

namespace KraftInk\Plugins\Shop\Services;

use App\Events\AccountDeleting;
use App\Events\ExportingPersonalData;
use App\Kraft\Extensions\Contracts\PrivacyHandler;
use Illuminate\Support\Facades\DB;
use KraftInk\Plugins\Shop\Models\Billing\CreditRecovery;
use KraftInk\Plugins\Shop\Models\Billing\Dispute;
use KraftInk\Plugins\Shop\Models\Billing\DocumentMessage;
use KraftInk\Plugins\Shop\Models\Billing\ManualPayment;
use KraftInk\Plugins\Shop\Models\Billing\ManualRefund;
use KraftInk\Plugins\Shop\Models\Billing\Receipt;
use KraftInk\Plugins\Shop\Models\Billing\Refund;
use KraftInk\Plugins\Shop\Models\Billing\WalletReturn;
use KraftInk\Plugins\Shop\Models\Catalog\Coupon;
use KraftInk\Plugins\Shop\Models\Catalog\CouponUsageBaseline;
use KraftInk\Plugins\Shop\Models\Delivery\Delivery;
use KraftInk\Plugins\Shop\Models\GiftCards\GiftCard;
use KraftInk\Plugins\Shop\Models\GiftCards\GiftCardIssuance;
use KraftInk\Plugins\Shop\Models\GiftCards\GiftCardMessage;
use KraftInk\Plugins\Shop\Models\Orders\Order;
use KraftInk\Plugins\Shop\Models\Orders\OrderItem;
use KraftInk\Plugins\Shop\Models\Orders\RoleGrant;
use KraftInk\Plugins\Shop\Models\Orders\Subscription;
use KraftInk\Plugins\Shop\Models\Orders\SubscriptionChange;
use KraftInk\Plugins\Shop\Models\Orders\SubscriptionTrial;
use KraftInk\Plugins\Shop\Models\Pricing\UpgradeState;
use KraftInk\Plugins\Shop\Services\Orders\OrderService;
use Illuminate\Database\Eloquent\Model;

class Privacy implements PrivacyHandler
{
    public function export(ExportingPersonalData $event): void
    {
        $user = $event->user->id;
        $event->data['shop'] = [
            'orders' => Order::query()
                ->where('user_id', $user)
                ->orderBy('id')
                ->select([
                    'id',
                    'reference',
                    'status',
                    'provider',
                    'provider_reference',
                    'currency',
                    'subtotal_minor',
                    'discount_minor',
                    'giftcard_minor',
                    'total_minor',
                    'refunded_minor',
                    'financial_hold',
                    'pricing_hold',
                    'giftcard_hold',
                    'pricing_review_reason',
                    'upgrade_credit_minor',
                    'created_at',
                    'paid_at',
                    'commercial_snapshot',
                ])
                ->addSelect('wallet_minor')
                ->cursor()
                ->map(function ($order) {
                    $snapshot = $order->commercial_snapshot ?? [];
                    $order->billing = $snapshot['billing'] ?? null;
                    unset($order->commercial_snapshot);

                    return $order;
                }),
            'items' => OrderItem::query()
                ->join('shop__orders', 'shop__orders.id', '=', 'shop__order_items.order_id')
                ->where('shop__orders.user_id', $user)
                ->orderBy('shop__order_items.id')
                ->select([
                    'shop__order_items.id',
                    'order_id',
                    'name',
                    'quantity',
                    'unit_minor',
                    'kind',
                    'discount_snapshot',
                    'giftcard_recipient_id',
                    'promotion_minor',
                    'shop__order_items.upgrade_credit_minor',
                    'paid_minor',
                    'pricing_snapshot',
                    'recipient_id',
                    'variables',
                    'role_days',
                    'wallet_credit_minor',
                    'wallet_currency',
                    'shop__order_items.game',
                    'player_uuid',
                    'player_name',
                ])
                ->cursor(),
            'subscriptions' => Subscription::query()
                ->where('user_id', $user)
                ->orderBy('id')
                ->select([
                    'id',
                    'order_id',
                    'provider',
                    'provider_reference',
                    'status',
                    'paid_until',
                    'cancel_at_period_end',
                    'created_at',
                ])
                ->cursor(),
            'refunds' => Refund::query()
                ->join('shop__orders', 'shop__orders.id', '=', 'shop__refunds.order_id')
                ->where('shop__orders.user_id', $user)
                ->orderBy('shop__refunds.created_at')
                ->select([
                    'shop__refunds.id',
                    'order_id',
                    'shop__refunds.provider',
                    'shop__refunds.provider_reference',
                    'amount_minor',
                    'external_minor',
                    'shop__refunds.giftcard_minor',
                    'shop__refunds.currency',
                    'shop__refunds.status',
                    'shop__refunds.created_at',
                    'processed_at',
                ])
                ->addSelect('shop__refunds.wallet_minor')
                ->cursor(),
            'disputes' => Dispute::query()
                ->join('shop__orders', 'shop__orders.id', '=', 'shop__disputes.order_id')
                ->where('shop__orders.user_id', $user)
                ->orderBy('shop__disputes.id')
                ->select([
                    'shop__disputes.id',
                    'order_id',
                    'shop__disputes.provider',
                    'shop__disputes.provider_reference',
                    'amount_minor',
                    'shop__disputes.currency',
                    'shop__disputes.status',
                    'shop__disputes.created_at',
                ])
                ->cursor(),
            'role_grants' => RoleGrant::query()
                ->where('user_id', $user)
                ->orderBy('id')
                ->select(['order_id', 'role_id', 'created_at', 'expires_at', 'revoked_at'])
                ->cursor(),
            'credit_recoveries' => CreditRecovery::query()
                ->where('user_id', $user)
                ->orderBy('id')
                ->select([
                    'order_id',
                    'order_item_id',
                    'amount_minor',
                    'recovered_minor',
                    'currency',
                    'created_at',
                    'updated_at',
                ])
                ->cursor(),
        ];
        $event->data['shop']['received_items'] = OrderItem::query()
            ->where('recipient_id', $user)
            ->whereHas('order', fn ($query) => $query->where('user_id', '!=', $user))
            ->orderBy('id')
            ->select([
                'id',
                'name',
                'quantity',
                'recipient_id',
                'game',
                'player_uuid',
                'player_name',
                'role_id',
                'role_days',
            ])
            ->cursor();
        $event->data['shop']['subscription_trials'] = SubscriptionTrial::query()
            ->where(fn ($query) => $query->where('user_id', $user)->orWhere('recipient_id', $user))
            ->orderBy('id')
            ->select(['family', 'started_at', 'ends_at', 'created_at'])
            ->cursor();
        $event->data['shop']['subscription_changes'] = SubscriptionChange::query()
            ->whereHas('subscription', fn ($query) => $query->where('user_id', $user))
            ->orderBy('created_at')
            ->select([
                'id',
                'subscription_id',
                'product_id',
                'status',
                'amount_minor',
                'effective_at',
                'created_at',
            ])
            ->cursor();
        $event->data['shop']['wallet_returns'] = WalletReturn::query()
            ->where('user_id', $user)
            ->orderBy('created_at')
            ->select([
                'id',
                'order_id',
                'refund_id',
                'amount_minor',
                'currency',
                'status',
                'transaction_reference',
                'created_at',
                'updated_at',
            ])
            ->cursor();
        $event->data['shop']['coupon_usage_baselines'] = CouponUsageBaseline::query()
            ->where('user_id', $user)
            ->orderBy('id')
            ->select(['coupon_id', 'uses', 'occurred_at'])
            ->cursor();
        $event->data['shop']['giftcard_messages'] = GiftCardMessage::query()
            ->where('user_id', $user)
            ->orderBy('id')
            ->select(['giftcard_id', 'event', 'status', 'attempt', 'consented_at', 'accepted_at', 'created_at'])
            ->cursor();
        $event->data['shop']['giftcards'] = GiftCard::query()
            ->from('shop__giftcards as cards')
            ->leftJoin('shop__order_items as items', 'items.id', '=', 'cards.source_item_id')
            ->leftJoin('shop__orders as orders', 'orders.id', '=', 'items.order_id')
            ->where(fn ($query) => $query->where('cards.owner_id', $user)->orWhere('orders.user_id', $user))
            ->orderBy('cards.id')
            ->select([
                'cards.id',
                'cards.last_four',
                'cards.currency',
                'cards.enabled',
                'cards.expires_at',
                'cards.claimed_at',
                'cards.created_at',
            ])
            ->selectRaw(
                'CASE WHEN cards.owner_id = ? OR cards.owner_id IS NULL THEN cards.balance_minor ELSE NULL END AS balance_minor',
                [$user],
            )
            ->cursor();
        $event->data['shop']['giftcard_issuances'] = GiftCardIssuance::query()
            ->from('shop__giftcard_issuances as issuances')
            ->join('shop__order_items as items', 'items.id', '=', 'issuances.order_item_id')
            ->join('shop__orders as orders', 'orders.id', '=', 'items.order_id')
            ->where('orders.user_id', $user)
            ->orderBy('issuances.id')
            ->select([
                'issuances.giftcard_id',
                'issuances.order_item_id',
                'issuances.face_minor',
                'issuances.created_at',
            ])
            ->cursor();
        foreach (['payments' => ManualPayment::class, 'refunds' => ManualRefund::class] as $kind => $model) {
            /** @var class-string<Model> $model */
            $event->data['shop']['manual_'.$kind] = $model::query()
                ->from('shop__manual_'.$kind.' as records')
                ->join('shop__orders as orders', 'orders.id', '=', 'records.order_id')
                ->where('orders.user_id', $user)
                ->orderBy('records.confirmed_at')
                ->select(
                    'records.id',
                    'records.order_id',
                    'records.reference',
                    'records.amount_minor',
                    'records.currency',
                    'records.confirmed_at',
                )
                ->cursor();
        }
        $event->data['shop']['upgrade_states'] = UpgradeState::query()
            ->where('user_id', $user)
            ->orderBy('id')
            ->select([
                'group_id',
                'current_item_id',
                'version',
                'weight',
                'expires_at',
                'status',
                'created_at',
                'updated_at',
            ])
            ->cursor();
        $event->data['shop']['receipts'] = Receipt::query()
            ->join('shop__orders', 'shop__orders.id', '=', 'shop__receipts.order_id')
            ->where('shop__orders.user_id', $user)
            ->orderBy('shop__receipts.id')
            ->select([
                'shop__receipts.id',
                'order_id',
                'number',
                'kind',
                'shop__receipts.currency',
                'gross_minor',
                'tax_minor',
                'net_minor',
                'rate_bps',
                'issued_at',
                'body_hash',
                'snapshot',
            ])
            ->cursor();
        $event->data['shop']['issued_giftcards'] = GiftCard::query()
            ->where('issuer_id', $user)
            ->orderBy('id')
            ->select(['id', 'last_four', 'balance_minor', 'currency', 'enabled', 'expires_at', 'created_at'])
            ->cursor();
        $event->data['shop']['issued_coupons'] = Coupon::query()
            ->where('issuer_id', $user)
            ->orderBy('id')
            ->select(['id', 'code', 'type', 'amount', 'currency', 'enabled', 'starts_at', 'expires_at'])
            ->cursor();
    }

    public function anonymize(AccountDeleting $event): void
    {
        $user = $event->user->id;
        $walletPending = WalletReturn::query()->where('user_id', $user)->where('status', 'pending')->exists();
        $walletReserved = Order::query()
            ->where('user_id', $user)
            ->where('status', 'pending')
            ->where('wallet_minor', '>', 0)
            ->exists();
        if ($walletPending || $walletReserved) {
            $event->blockedReason = __('shop::commerce.refunds.account_pending');

            return;
        }
        $subscription = Subscription::query()
            ->where(
                fn ($query) => $query
                    ->where('user_id', $user)
                    ->orWhereHas('order.items', fn ($query) => $query->where('recipient_id', $user)),
            )
            ->whereIn('status', ['active', 'past_due', 'trialing', 'suspended', 'approval_pending'])
            ->exists();
        $refund = Refund::query()
            ->join('shop__orders', 'shop__orders.id', '=', 'shop__refunds.order_id')
            ->where('shop__orders.user_id', $user)
            ->whereIn('shop__refunds.status', ['pending', 'processing'])
            ->exists();
        $dispute = Dispute::query()
            ->join('shop__orders', 'shop__orders.id', '=', 'shop__disputes.order_id')
            ->where('shop__orders.user_id', $user)
            ->whereIn('shop__disputes.status', ['open', 'review'])
            ->exists();
        $credit = CreditRecovery::query()
            ->where('user_id', $user)
            ->whereColumn('recovered_minor', '<', 'amount_minor')
            ->exists();
        if (GiftCard::query()->where('owner_id', $user)->where('balance_minor', '>', 0)->exists()) {
            $event->blockedReason = __('shop::commerce.giftcards.account_pending');

            return;
        }
        if ($subscription || $refund || $dispute || $credit) {
            $event->blockedReason = __('shop::commerce.refunds.account_pending');

            return;
        }
        foreach (
            Order::query()
                ->where(
                    fn ($query) => $query
                        ->where('user_id', $user)
                        ->orWhereHas('items', fn ($query) => $query->where('recipient_id', $user)),
                )
                ->where('status', 'pending')
                ->pluck('id') as $orderId
        ) {
            app(OrderService::class)->cancel($orderId);
        }
        DB::transaction(function () use ($user) {
            Order::query()
                ->where('user_id', $user)
                ->whereNull('paid_at')
                ->whereNotNull('commercial_snapshot')
                ->select('id', 'commercial_snapshot')
                ->lockForUpdate()
                ->chunkById(100, function ($orders) {
                    foreach ($orders as $order) {
                        $snapshot = $order->commercial_snapshot ?? [];
                        if (!is_array($snapshot) || !array_key_exists('billing', $snapshot)) {
                            continue;
                        }
                        unset($snapshot['billing']);
                        Order::withoutTimestamps(
                            fn () => Order::query()
                                ->where('id', $order->id)
                                ->whereNull('paid_at')
                                ->update(['commercial_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]),
                        );
                    }
                });
        }, 3);
        DocumentMessage::query()
            ->where('status', 'pending')
            ->whereIn('receipt_id', function ($query) use ($user) {
                $query
                    ->from('shop__receipts')
                    ->join('shop__orders', 'shop__orders.id', '=', 'shop__receipts.order_id')
                    ->where('shop__orders.user_id', $user)
                    ->select('shop__receipts.id');
            })
            ->update(['status' => 'cancelled', 'updated_at' => now()]);
        RoleGrant::query()
            ->where('user_id', $user)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revocation_reason' => 'account_deleted']);
        Delivery::query()
            ->where(
                fn ($query) => $query
                    ->where('user_id', $user)
                    ->orWhereHas('orderItem', fn ($query) => $query->where('recipient_id', $user)),
            )
            ->whereIn('status', ['pending', 'processing'])
            ->update(['status' => 'cancelled', 'last_error' => 'account_deleted']);
        UpgradeState::query()
            ->where('user_id', $user)
            ->update(['status' => 'review', 'updated_at' => now()]);
        CouponUsageBaseline::query()
            ->where('user_id', $user)
            ->update(['user_id' => null]);
        GiftCardMessage::query()
            ->where('user_id', $user)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled', 'updated_at' => now()]);
        GiftCard::query()
            ->where('issuer_id', $user)
            ->update(['issuer_id' => null]);
        Coupon::query()
            ->where('issuer_id', $user)
            ->update(['issuer_id' => null]);
        foreach (['payments' => ManualPayment::class, 'refunds' => ManualRefund::class] as $kind => $model) {
            /** @var class-string<Model> $model */
            $model::query()
                ->where('actor_id', $user)
                ->update(['actor_id' => null]);
        }
    }
}
