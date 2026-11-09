<?php

namespace KraftInk\Plugins\Shop\Models\Orders;

use Illuminate\Support\Carbon;
use App\Kraft\Games\GameRegistry;
use App\Models\Accounts\Role;
use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int $user_id
 * @property int $role_id
 * @property int $order_id
 * @property string|null $revocation_reason
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 * @property-read User|null $user
 * @property-read Order|null $order
 */
class RoleGrant extends Model
{
    protected $table = 'shop__role_grants';

    protected $guarded = ['id'];

    public const null UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'role_id' => 'integer',
            'order_id' => 'integer',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function scopeActive(Builder $query): void
    {
        $game = app(GameRegistry::class)->activeId();
        $query
            ->whereNull('shop__role_grants.revoked_at')
            ->where(
                fn (Builder $query) => $query
                    ->whereNull('shop__role_grants.expires_at')
                    ->orWhere('shop__role_grants.expires_at', '>', now()),
            )
            ->whereHas(
                'order',
                fn (Builder $order) => $order
                    ->entitled()
                    ->whereHas(
                        'items',
                        fn (Builder $item) => $item
                            ->whereColumn('shop__order_items.role_id', 'shop__role_grants.role_id')
                            ->where(
                                fn (Builder $recipient) => $recipient
                                    ->whereNull('shop__order_items.recipient_id')
                                    ->orWhereColumn('shop__order_items.recipient_id', 'shop__role_grants.user_id'),
                            )
                            ->where(
                                fn (Builder $identity) => $identity
                                    ->whereNull('shop__order_items.game')
                                    ->orWhere('shop__order_items.game', $game),
                            ),
                    ),
            );
    }
}
