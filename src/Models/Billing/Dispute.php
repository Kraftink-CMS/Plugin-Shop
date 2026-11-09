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
 * @property string $provider
 * @property string $provider_reference
 * @property string $payment_reference
 * @property string $currency
 * @property int $amount_minor
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Order|null $order
 */
class Dispute extends Model
{
    protected $table = 'shop__disputes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'amount_minor' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
