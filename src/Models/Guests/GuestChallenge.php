<?php

namespace KraftInk\Plugins\Shop\Models\Guests;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin Builder<static>
 *
 * @property string $id
 * @property int $guest_id
 * @property string $code_hash
 * @property string $browser_hash
 * @property int $attempts
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property Carbon $created_at
 * @property-read Guest|null $guest
 */
class GuestChallenge extends Model
{
    use HasUuids;

    protected $table = 'shop__guest_challenges';

    protected $guarded = ['id'];

    public const null UPDATED_AT = null;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = ['code_hash', 'browser_hash'];

    protected function casts(): array
    {
        return [
            'guest_id' => 'integer',
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'guest_id');
    }
}
