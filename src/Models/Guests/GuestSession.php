<?php

namespace KraftInk\Plugins\Shop\Models\Guests;

use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin Builder<static>
 *
 * @method static Builder<static> usable()
 *
 * @property-read Guest $guest
 * @property string $id
 * @property int $guest_id
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 */
class GuestSession extends Model
{
    use HasUuids;

    protected $table = 'shop__guest_sessions';

    protected $guarded = ['id'];

    public const null UPDATED_AT = null;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'guest_id' => 'integer',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'guest_id');
    }

    public function scopeUsable(Builder $query): void
    {
        $query
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->whereHas('guest', fn (Builder $guest) => $guest->verified());
    }
}
