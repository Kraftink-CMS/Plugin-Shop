<?php

namespace KraftInk\Plugins\Shop\Models\GiftCards;

use Illuminate\Support\Carbon;
use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use KraftInk\Plugins\Shop\Models\Orders\Order;
use KraftInk\Plugins\Shop\Models\Orders\OrderItem;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int|null $owner_id
 * @property int|null $recipient_id
 * @property int|null $issuer_id
 * @property int|null $source_item_id
 * @property string $code_hash
 * @property string $last_four
 * @property string|null $secret
 * @property string|null $claim_secret
 * @property string|null $claim_token_hash
 * @property int $balance_minor
 * @property string $currency
 * @property array|null $issuer_permissions
 * @property int|null $issuer_position
 * @property bool $enabled
 * @property Carbon|null $expires_at
 * @property Carbon|null $claimed_at
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 * @property-read User|null $owner
 * @property-read OrderItem|null $sourceItem
 * @property-read GiftCardIssuance|null $issuance
 */
class GiftCard extends Model
{
    protected $table = 'shop__giftcards';

    protected $guarded = ['id'];

    public const null UPDATED_AT = null;

    protected $hidden = ['code_hash', 'secret', 'claim_secret', 'claim_token_hash'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'claim_secret' => 'encrypted',
            'owner_id' => 'integer',
            'recipient_id' => 'integer',
            'issuer_id' => 'integer',
            'source_item_id' => 'integer',
            'balance_minor' => 'integer',
            'issuer_permissions' => 'array',
            'issuer_position' => 'integer',
            'enabled' => 'boolean',
            'expires_at' => 'datetime',
            'claimed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issuer_id');
    }

    public function sourceItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'source_item_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'giftcard_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(GiftCardMessage::class, 'giftcard_id');
    }

    public function issuance(): HasOne
    {
        return $this->hasOne(GiftCardIssuance::class, 'giftcard_id');
    }

    public function holds(): HasMany
    {
        return $this->hasMany(GiftCardHold::class, 'giftcard_id');
    }

    public function isSpendable(): bool
    {
        return $this->enabled &&
            !$this->revoked_at &&
            $this->balance_minor > 0 &&
            (!$this->expires_at || now()->lt($this->expires_at));
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(
            fn (Builder $query) => $query
                ->where('shop__giftcards.owner_id', $user->id)
                ->orWhereHas('sourceItem.order', fn (Builder $query) => $query->where('user_id', $user->id)),
        );
    }
}
