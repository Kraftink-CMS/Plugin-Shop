<?php

namespace KraftInk\Plugins\Shop\Models\Pricing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use KraftInk\Plugins\Shop\Models\Orders\OrderItem;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int $user_id
 * @property int $group_id
 * @property int|null $current_item_id
 * @property int|null $pending_order_id
 * @property int $version
 * @property int $weight
 * @property string $status
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 */
class UpgradeState extends Model
{
    protected $table = 'shop__upgrade_states';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'group_id' => 'integer',
            'current_item_id' => 'integer',
            'pending_order_id' => 'integer',
            'version' => 'integer',
            'weight' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(UpgradeGroup::class, 'group_id');
    }

    public function currentItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'current_item_id');
    }
}
