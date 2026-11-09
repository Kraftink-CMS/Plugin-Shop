<?php

namespace KraftInk\Plugins\Shop\Models\GiftCards;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use KraftInk\Plugins\Shop\Models\Orders\OrderItem;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int $order_item_id
 * @property int $giftcard_id
 * @property int $unit_number
 * @property int $face_minor
 * @property Carbon|null $created_at
 */
class GiftCardIssuance extends Model
{
    protected $table = 'shop__giftcard_issuances';

    protected $guarded = ['id'];

    public const null UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'order_item_id' => 'integer',
            'giftcard_id' => 'integer',
            'unit_number' => 'integer',
            'face_minor' => 'integer',
        ];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function giftCard(): BelongsTo
    {
        return $this->belongsTo(GiftCard::class, 'giftcard_id');
    }
}
