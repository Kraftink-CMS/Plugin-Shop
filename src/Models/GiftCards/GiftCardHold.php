<?php

namespace KraftInk\Plugins\Shop\Models\GiftCards;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use KraftInk\Plugins\Shop\Models\Orders\Order;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int $giftcard_id
 * @property int $order_id
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Order|null $order
 */
class GiftCardHold extends Model
{
    protected $table = 'shop__giftcard_holds';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'giftcard_id' => 'integer',
            'order_id' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function giftCard(): BelongsTo
    {
        return $this->belongsTo(GiftCard::class, 'giftcard_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
