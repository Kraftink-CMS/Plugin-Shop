<?php

namespace KraftInk\Plugins\Shop\Models\Orders;

use Illuminate\Database\Eloquent\Builder;
use App\Models\Accounts\Role;
use App\Models\Accounts\User;
use App\Models\Servers\Server;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use KraftInk\Plugins\Shop\Models\Catalog\Category;
use KraftInk\Plugins\Shop\Models\Catalog\Product;
use KraftInk\Plugins\Shop\Models\Catalog\ProductCode;
use KraftInk\Plugins\Shop\Models\Delivery\Delivery;
use KraftInk\Plugins\Shop\Models\GiftCards\GiftCardIssuance;
use KraftInk\Plugins\Shop\Models\Pricing\UpgradeAllocation;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $product_id
 * @property int|null $category_id
 * @property int|null $role_id
 * @property int|null $server_id
 * @property int|null $recipient_id
 * @property int|null $giftcard_recipient_id
 * @property string $kind
 * @property string|null $game
 * @property string|null $player_uuid
 * @property string|null $player_name
 * @property string $name
 * @property int $quantity
 * @property int $unit_minor
 * @property int $promotion_minor
 * @property int $upgrade_credit_minor
 * @property int|null $paid_minor
 * @property array|null $pricing_snapshot
 * @property array|null $discount_snapshot
 * @property int $wallet_credit_minor
 * @property string|null $wallet_currency
 * @property string|null $billing_interval
 * @property int|null $role_days
 * @property string|null $upgrade_group
 * @property int $upgrade_weight
 * @property string|null $commands
 * @property string|null $refund_commands
 * @property string|null $command_identity
 * @property array|null $variables
 * @property string|null $download_path
 * @property int|null $giftcard_days
 * @property bool $requires_online
 * @property-read Order|null $order
 * @property-read Category|null $category
 * @property-read Server|null $server
 */
class OrderItem extends Model
{
    protected $table = 'shop__order_items';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'product_id' => 'integer',
            'category_id' => 'integer',
            'role_id' => 'integer',
            'server_id' => 'integer',
            'recipient_id' => 'integer',
            'giftcard_recipient_id' => 'integer',
            'quantity' => 'integer',
            'unit_minor' => 'integer',
            'promotion_minor' => 'integer',
            'upgrade_credit_minor' => 'integer',
            'paid_minor' => 'integer',
            'pricing_snapshot' => 'array',
            'discount_snapshot' => 'array',
            'wallet_credit_minor' => 'integer',
            'role_days' => 'integer',
            'trial_days' => 'integer',
            'trial_requires_payment_method' => 'boolean',
            'upgrade_weight' => 'integer',
            'variables' => 'array',
            'giftcard_days' => 'integer',
            'requires_online' => 'boolean',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function canDownload(): bool
    {
        if (!$this->download_path || !$this->order || !$this->order->isEntitled()) {
            return false;
        }

        if (!$this->order->recurring || Order::query()->whereKey($this->order_id)->trialing()->exists()) {
            return true;
        }
        $payment = SubscriptionPayment::query()
            ->where('order_id', $this->order_id)
            ->where('period_end', '>', now())
            ->first();
        if (!$payment) {
            return false;
        }

        return !SubscriptionPayment::query()
            ->where('subscription_id', $payment->subscription_id)
            ->where('period_end', '>=', $payment->period_end)
            ->where('id', '>', $payment->id)
            ->exists();
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class, 'order_item_id');
    }

    public function codes(): HasMany
    {
        return $this->hasMany(ProductCode::class, 'order_item_id');
    }

    public function giftCardIssuances(): HasMany
    {
        return $this->hasMany(GiftCardIssuance::class, 'order_item_id');
    }

    public function upgradeAllocations(): HasMany
    {
        return $this->hasMany(UpgradeAllocation::class, 'target_item_id');
    }
}
