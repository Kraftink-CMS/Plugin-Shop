<?php

namespace KraftInk\Plugins\Shop\Models\Orders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int $subscription_id
 * @property int $order_id
 * @property string $provider_payment
 * @property Carbon $period_end
 * @property Carbon $created_at
 * @property-read Order|null $order
 */
class SubscriptionPayment extends Model
{
    protected $table = 'shop__subscription_payments';

    protected $guarded = ['id'];

    public const null UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'subscription_id' => 'integer',
            'order_id' => 'integer',
            'period_end' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
