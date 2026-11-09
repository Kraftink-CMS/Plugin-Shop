<?php

namespace KraftInk\Plugins\Shop\Models\Guests;

use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use KraftInk\Plugins\Shop\Models\Orders\Order;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property string $email
 * @property string|null $email_address
 * @property string $email_hash
 * @property string $locale
 * @property Carbon|null $verified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Guest extends Model
{
    protected $table = 'shop__guests';

    protected $guarded = ['id'];

    protected $hidden = ['email_hash', 'email'];

    protected function casts(): array
    {
        return [
            'email' => 'encrypted',
            'verified_at' => 'datetime',
        ];
    }

    public function challenges(): HasMany
    {
        return $this->hasMany(GuestChallenge::class, 'guest_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(GuestSession::class, 'guest_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'guest_id');
    }

    public function scopeVerified(Builder $query): void
    {
        $query->whereNotNull('verified_at');
    }
}
