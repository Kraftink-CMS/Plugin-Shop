<?php

namespace KraftInk\Plugins\Shop\Models\GiftCards;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int $giftcard_id
 * @property int $user_id
 * @property string $event
 * @property string $address_hash
 * @property string $status
 * @property int $attempt
 * @property Carbon $consented_at
 * @property Carbon|null $queued_at
 * @property Carbon|null $started_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 */
class GiftCardMessage extends Model
{
    protected $table = 'shop__giftcard_messages';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'giftcard_id' => 'integer',
            'user_id' => 'integer',
            'attempt' => 'integer',
            'consented_at' => 'datetime',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function giftCard(): BelongsTo
    {
        return $this->belongsTo(GiftCard::class, 'giftcard_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
