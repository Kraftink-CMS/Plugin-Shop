<?php

namespace KraftInk\Plugins\Shop\Models\Orders;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use KraftInk\Plugins\Shop\Models\Billing\Dispute;
use KraftInk\Plugins\Shop\Models\Billing\FinancialEvent;
use KraftInk\Plugins\Shop\Models\Billing\Receipt;
use KraftInk\Plugins\Shop\Models\Billing\Refund;
use KraftInk\Plugins\Shop\Models\Catalog\Coupon;
use KraftInk\Plugins\Shop\Models\Delivery\Delivery;
use KraftInk\Plugins\Shop\Models\GiftCards\GiftCard;
use KraftInk\Plugins\Shop\Models\Guests\Guest;

/**
 * @mixin Builder<static>
 *
 * @method static Builder<static> usable()
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $guest_id
 * @property int|null $coupon_id
 * @property int|null $giftcard_id
 * @property string $reference
 * @property string $checkout_key
 * @property string|null $checkout_hash
 * @property string $provider
 * @property string|null $provider_reference
 * @property string|null $payment_reference
 * @property string|null $payment_kind
 * @property string|null $checkout_url
 * @property string|null $payment_instructions
 * @property string $currency
 * @property int $subtotal_minor
 * @property int $discount_minor
 * @property int $upgrade_credit_minor
 * @property int $giftcard_minor
 * @property int $wallet_minor
 * @property int $total_minor
 * @property int $refunded_minor
 * @property array|null $commercial_snapshot
 * @property bool $recurring
 * @property bool $auto_expire
 * @property bool $financial_hold
 * @property bool $financial_review
 * @property bool $pricing_hold
 * @property string|null $pricing_review_reason
 * @property bool $giftcard_hold
 * @property string $status
 * @property Carbon $expires_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $released_at
 * @property Carbon|null $reversed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read Guest|null $guest
 * @property-read Collection<int, OrderItem> $items
 */
class Order extends Model
{
    protected $table = 'shop__orders';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'guest_id' => 'integer',
            'coupon_id' => 'integer',
            'giftcard_id' => 'integer',
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'upgrade_credit_minor' => 'integer',
            'giftcard_minor' => 'integer',
            'wallet_minor' => 'integer',
            'total_minor' => 'integer',
            'refunded_minor' => 'integer',
            'commercial_snapshot' => 'array',
            'recurring' => 'boolean',
            'auto_expire' => 'boolean',
            'financial_hold' => 'boolean',
            'financial_review' => 'boolean',
            'pricing_hold' => 'boolean',
            'giftcard_hold' => 'boolean',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'released_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'guest_id');
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'coupon_id');
    }

    public function giftCard(): BelongsTo
    {
        return $this->belongsTo(GiftCard::class, 'giftcard_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'order_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class, 'order_id');
    }

    public function paymentEvents(): HasMany
    {
        return $this->hasMany(PaymentEvent::class, 'order_id');
    }

    public function roleGrants(): HasMany
    {
        return $this->hasMany(RoleGrant::class, 'order_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class, 'order_id');
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class, 'order_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class, 'order_id');
    }

    public function financialEvents(): HasMany
    {
        return $this->hasMany(FinancialEvent::class, 'order_id');
    }

    public function hasValidBuyer(): bool
    {
        return isset($this->user_id) !== isset($this->guest_id);
    }

    public function isUsable(): bool
    {
        return $this->hasValidBuyer() &&
            $this->status === 'paid' &&
            !$this->financial_hold &&
            !$this->pricing_hold &&
            !$this->giftcard_hold;
    }

    public function isPayable(): bool
    {
        return $this->hasValidBuyer() &&
            $this->status === 'pending' &&
            !$this->released_at &&
            !$this->financial_hold &&
            !$this->pricing_hold &&
            !$this->giftcard_hold &&
            (!$this->recurring ||
                !$this->subscriptions()
                    ->where(
                        fn (Builder $query) => $query
                            ->whereIn('status', ['trialing', 'active'])
                            ->orWhereHas('trial', fn (Builder $trial) => $trial->whereNotNull('ends_at')),
                    )
                    ->exists()) &&
            (!$this->auto_expire || now()->lt($this->expires_at));
    }

    public function scopeTrialing(Builder $query): void
    {
        $table = $query->getModel()->getTable();
        $query
            ->where($table.'.status', 'pending')
            ->where($table.'.recurring', true)
            ->whereNotNull($table.'.user_id')
            ->whereNull($table.'.guest_id')
            ->whereNull($table.'.released_at')
            ->where($table.'.financial_hold', false)
            ->where($table.'.pricing_hold', false)
            ->where($table.'.giftcard_hold', false)
            ->whereHas(
                'subscriptions',
                fn (Builder $subscription) => $subscription
                    ->where('status', 'trialing')
                    ->whereRelation('trial', 'ends_at', '>', now()),
            )
            ->whereHas('items', fn (Builder $item) => $item->where('trial_days', '>', 0));
    }

    public function scopeEntitled(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query->usable()->orWhere(fn (Builder $query) => $query->trialing()));
    }

    public function isEntitled(): bool
    {
        return $this->isUsable() || $this->newQuery()->whereKey($this->id)->trialing()->exists();
    }

    public function scopeUsable(Builder $query, ?string $table = null): void
    {
        $table ??= $query->getModel()->getTable();
        $query
            ->where(
                fn (Builder $query) => $query
                    ->where(
                        fn (Builder $query) => $query
                            ->whereNotNull($table.'.user_id')
                            ->whereNull($table.'.guest_id'),
                    )
                    ->orWhere(
                        fn (Builder $query) => $query
                            ->whereNull($table.'.user_id')
                            ->whereNotNull($table.'.guest_id'),
                    ),
            )
            ->where($table.'.status', 'paid')
            ->where($table.'.financial_hold', false)
            ->where($table.'.pricing_hold', false)
            ->where($table.'.giftcard_hold', false);
    }
}
