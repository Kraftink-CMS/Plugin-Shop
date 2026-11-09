<?php

namespace KraftInk\Plugins\Shop\Models\Catalog;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int $coupon_id
 * @property int|null $user_id
 * @property string $source_key
 * @property int $uses
 * @property Carbon|null $occurred_at
 * @property-read User|null $user
 */
class CouponUsageBaseline extends Model
{
    protected $table = 'shop__coupon_usage_baselines';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'coupon_id' => 'integer',
            'user_id' => 'integer',
            'uses' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'coupon_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
