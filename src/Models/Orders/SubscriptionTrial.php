<?php

namespace KraftInk\Plugins\Shop\Models\Orders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Accounts\User;

class SubscriptionTrial extends Model
{
    protected $table = 'shop__subscription_trials';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'subscription_id' => 'integer',
            'user_id' => 'integer',
            'recipient_id' => 'integer',
            'started_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }
}
