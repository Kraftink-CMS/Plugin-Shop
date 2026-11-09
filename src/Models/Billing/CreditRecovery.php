<?php

namespace KraftInk\Plugins\Shop\Models\Billing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use KraftInk\Plugins\Shop\Models\Orders\Order;
use KraftInk\Plugins\Shop\Models\Orders\OrderItem;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int $order_item_id
 * @property int $order_id
 * @property int $user_id
 * @property string $currency
 * @property int $amount_minor
 * @property int $recovered_minor
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Order|null $order
 * @property-read User|null $user
 */
class CreditRecovery extends Model
{
    protected $table = 'shop__credit_recoveries';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'order_item_id' => 'integer',
            'order_id' => 'integer',
            'user_id' => 'integer',
            'amount_minor' => 'integer',
            'recovered_minor' => 'integer',
        ];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
