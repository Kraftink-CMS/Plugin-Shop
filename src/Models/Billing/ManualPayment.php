<?php

namespace KraftInk\Plugins\Shop\Models\Billing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use KraftInk\Plugins\Shop\Models\Orders\Order;

/**
 * @mixin Builder<static>
 *
 * @property string $id
 * @property int $order_id
 * @property int|null $actor_id
 * @property string $reference
 * @property string $reference_hash
 * @property int $amount_minor
 * @property string $currency
 * @property string $note
 * @property Carbon $confirmed_at
 * @property-read Order|null $order
 * @property-read User|null $actor
 */
class ManualPayment extends Model
{
    use HasUuids;

    protected $table = 'shop__manual_payments';

    protected $guarded = ['id'];

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'actor_id' => 'integer',
            'amount_minor' => 'integer',
            'confirmed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
