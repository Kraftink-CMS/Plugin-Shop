<?php

namespace KraftInk\Plugins\Shop\Models\Billing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use KraftInk\Plugins\Shop\Models\Orders\Order;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int $order_id
 * @property string $event_key
 * @property string $type
 * @property string $reference
 * @property string $currency
 * @property int $amount_minor
 * @property string $status
 * @property Carbon $created_at
 * @property-read Order|null $order
 */
class FinancialEvent extends Model
{
    protected $table = 'shop__financial_events';

    protected $guarded = ['id'];

    public const null UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'amount_minor' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
