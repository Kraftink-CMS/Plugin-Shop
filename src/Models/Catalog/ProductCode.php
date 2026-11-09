<?php

namespace KraftInk\Plugins\Shop\Models\Catalog;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use KraftInk\Plugins\Shop\Models\Orders\OrderItem;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $order_item_id
 * @property string $code_hash
 * @property string $value
 * @property Carbon $created_at
 */
class ProductCode extends Model
{
    protected $table = 'shop__product_codes';

    protected $guarded = ['id'];

    public const null UPDATED_AT = null;

    protected $hidden = ['code_hash', 'value'];

    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
            'product_id' => 'integer',
            'order_item_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }
}
