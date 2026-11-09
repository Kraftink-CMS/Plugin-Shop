<?php

namespace KraftInk\Plugins\Shop\Models\Billing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use KraftInk\Plugins\Shop\Models\Orders\Order;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $parent_id
 * @property string $number
 * @property string $source_key
 * @property string $kind
 * @property string $currency
 * @property int $gross_minor
 * @property int $tax_minor
 * @property int $net_minor
 * @property int $rate_bps
 * @property array $snapshot
 * @property string $body
 * @property string $body_hash
 * @property Carbon $issued_at
 * @property-read Order|null $order
 * @property-read Receipt|null $parent
 */
class Receipt extends Model
{
    protected $table = 'shop__receipts';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'parent_id' => 'integer',
            'gross_minor' => 'integer',
            'tax_minor' => 'integer',
            'net_minor' => 'integer',
            'rate_bps' => 'integer',
            'snapshot' => 'array',
            'issued_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Receipt::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Receipt::class, 'parent_id');
    }
}
