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
 * @property string|null $refund_id
 * @property int $user_id
 * @property string $source_key
 * @property string|null $transaction_reference
 * @property string $currency
 * @property int $amount_minor
 * @property string $status
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Order|null $order
 * @property-read User|null $user
 */
class WalletReturn extends Model
{
    use HasUuids;

    protected $table = 'shop__wallet_returns';

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'user_id' => 'integer',
            'amount_minor' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class, 'refund_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
