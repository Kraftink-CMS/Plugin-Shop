<?php

namespace KraftInk\Plugins\Shop\Models\Pricing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use KraftInk\Plugins\Shop\Models\Orders\OrderItem;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int $target_item_id
 * @property int $source_item_id
 * @property int $retained_minor
 * @property int $credited_minor
 * @property Carbon $created_at
 * @property-read OrderItem|null $sourceItem
 */
class UpgradeAllocation extends Model
{
    protected $table = 'shop__upgrade_allocations';

    protected $guarded = ['id'];

    public const null UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'target_item_id' => 'integer',
            'source_item_id' => 'integer',
            'retained_minor' => 'integer',
            'credited_minor' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function sourceItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'source_item_id');
    }
}
