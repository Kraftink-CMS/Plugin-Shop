<?php

namespace KraftInk\Plugins\Shop\Models\Catalog;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use App\Models\Accounts\Role;
use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use KraftInk\Plugins\Shop\Models\Orders\Order;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int|null $role_id
 * @property int|null $issuer_id
 * @property string $code
 * @property string $type
 * @property int $amount
 * @property string $currency
 * @property int $minimum_minor
 * @property int|null $max_uses
 * @property int|null $per_user_limit
 * @property string|null $period
 * @property array|null $product_ids
 * @property array|null $category_ids
 * @property array|null $issuer_permissions
 * @property int|null $issuer_position
 * @property bool $enabled
 * @property Carbon|null $starts_at
 * @property Carbon|null $expires_at
 */
class Coupon extends Model
{
    protected $table = 'shop__coupons';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'role_id' => 'integer',
            'issuer_id' => 'integer',
            'amount' => 'integer',
            'minimum_minor' => 'integer',
            'max_uses' => 'integer',
            'per_user_limit' => 'integer',
            'product_ids' => 'array',
            'category_ids' => 'array',
            'issuer_permissions' => 'array',
            'issuer_position' => 'integer',
            'enabled' => 'boolean',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issuer_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'coupon_id');
    }

    public function usageBaselines(): HasMany
    {
        return $this->hasMany(CouponUsageBaseline::class, 'coupon_id');
    }
}
