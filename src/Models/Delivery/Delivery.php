<?php

namespace KraftInk\Plugins\Shop\Models\Delivery;

use Illuminate\Support\Carbon;
use App\Models\Accounts\User;
use App\Models\Servers\Server;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use KraftInk\Plugins\Shop\Models\Guests\Guest;
use KraftInk\Plugins\Shop\Models\Orders\Order;
use KraftInk\Plugins\Shop\Models\Orders\OrderItem;

/**
 * @mixin Builder<static>
 *
 * @method static Builder<static> deliverable()
 *
 * @property string $id
 * @property int $order_id
 * @property int|null $order_item_id
 * @property int|null $user_id
 * @property int|null $guest_id
 * @property int|null $server_id
 * @property int|null $link_server_id
 * @property string $kind
 * @property string|null $game
 * @property string|null $player_uuid
 * @property string|null $player_name
 * @property string $command
 * @property bool $requires_online
 * @property string $status
 * @property int $attempts
 * @property string|null $last_error
 * @property string|null $lease_token
 * @property Carbon|null $available_at
 * @property Carbon|null $lease_expires_at
 * @property Carbon|null $delivered_at
 * @property Carbon $created_at
 * @property-read Order|null $order
 * @property-read User|null $user
 * @property-read Guest|null $guest
 * @property-read Server|null $server
 */
class Delivery extends Model
{
    use HasUuids;

    protected $table = 'shop__deliveries';

    protected $guarded = ['id'];

    public const null UPDATED_AT = null;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = ['lease_token'];

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'order_item_id' => 'integer',
            'user_id' => 'integer',
            'guest_id' => 'integer',
            'server_id' => 'integer',
            'link_server_id' => 'integer',
            'requires_online' => 'boolean',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'delivered_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'guest_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }

    public function linkServer(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'link_server_id');
    }

    public function scopeDeliverable(Builder $query): void
    {
        $query->whereHas('order', function (Builder $orders) {
            $orders
                ->where(
                    fn (Builder $buyer) => $buyer
                        ->where(
                            fn (Builder $user) => $user
                                ->whereNotNull('shop__orders.user_id')
                                ->whereNull('shop__orders.guest_id')
                                ->whereColumn('shop__orders.user_id', 'shop__deliveries.user_id')
                                ->whereNull('shop__deliveries.guest_id'),
                        )
                        ->orWhere(
                            fn (Builder $guest) => $guest
                                ->whereNull('shop__orders.user_id')
                                ->whereNotNull('shop__orders.guest_id')
                                ->whereColumn('shop__orders.guest_id', 'shop__deliveries.guest_id')
                                ->whereNull('shop__deliveries.user_id'),
                        ),
                )
                ->where(
                    fn (Builder $state) => $state
                        ->where(fn (Builder $grant) => $grant->where('shop__deliveries.kind', 'grant')->usable())
                        ->orWhere(
                            fn (Builder $reversal) => $reversal
                                ->where('shop__deliveries.kind', 'reversal')
                                ->whereIn('shop__orders.status', ['refunded', 'charged_back']),
                        ),
                );
        });
    }
}
