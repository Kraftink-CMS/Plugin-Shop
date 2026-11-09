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
 * @property string $request_key
 * @property string $provider
 * @property string|null $payment_reference
 * @property string|null $provider_reference
 * @property string $currency
 * @property int $amount_minor
 * @property int $external_minor
 * @property int $giftcard_minor
 * @property int $wallet_minor
 * @property string $reason
 * @property string $status
 * @property string|null $error
 * @property Carbon|null $submitted_at
 * @property Carbon|null $processed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Order|null $order
 * @property-read User|null $actor
 */
class Refund extends Model
{
    use HasUuids;

    protected $table = 'shop__refunds';

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'actor_id' => 'integer',
            'amount_minor' => 'integer',
            'external_minor' => 'integer',
            'giftcard_minor' => 'integer',
            'wallet_minor' => 'integer',
            'submitted_at' => 'datetime',
            'processed_at' => 'datetime',
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
