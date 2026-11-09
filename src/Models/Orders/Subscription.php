<?php

namespace KraftInk\Plugins\Shop\Models\Orders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use KraftInk\Plugins\Shop\Models\Catalog\Product;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int $order_id
 * @property int $user_id
 * @property int|null $product_id
 * @property string $provider
 * @property string $provider_reference
 * @property bool $cancel_at_period_end
 * @property string $status
 * @property Carbon|null $paid_until
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Order|null $order
 * @property-read User|null $user
 */
class Subscription extends Model
{
    protected $table = 'shop__subscriptions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'user_id' => 'integer',
            'product_id' => 'integer',
            'cancel_at_period_end' => 'boolean',
            'paid_until' => 'datetime',
            'plan_snapshot' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class, 'subscription_id');
    }

    public function trial(): HasOne
    {
        return $this->hasOne(SubscriptionTrial::class, 'subscription_id');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(SubscriptionChange::class, 'subscription_id');
    }
}
