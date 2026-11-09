<?php

namespace KraftInk\Plugins\Shop\Models\Catalog;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use App\Models\Accounts\Role;
use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int $role_id
 * @property int|null $category_id
 * @property int|null $product_id
 * @property int|null $issuer_id
 * @property string $name
 * @property string $game
 * @property string $currency
 * @property string $scope
 * @property string $type
 * @property int $amount
 * @property bool $combine_coupon
 * @property array|null $issuer_permissions
 * @property int|null $issuer_position
 * @property bool $enabled
 * @property Carbon|null $starts_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Category|null $category
 */
class PrivilegedPrice extends Model
{
    protected $table = 'shop__privileged_prices';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'role_id' => 'integer',
            'category_id' => 'integer',
            'product_id' => 'integer',
            'issuer_id' => 'integer',
            'amount' => 'integer',
            'combine_coupon' => 'boolean',
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

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issuer_id');
    }
}
