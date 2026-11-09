<?php

namespace KraftInk\Plugins\Shop\Models\Orders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use KraftInk\Plugins\Shop\Models\Catalog\Product;

class SubscriptionChange extends Model
{
    protected $table = 'shop__subscription_changes';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'subscription_id' => 'integer',
            'product_id' => 'integer',
            'order_id' => 'integer',
            'amount_minor' => 'integer',
            'snapshot' => 'array',
            'funding_snapshot' => 'array',
            'proration_at' => 'datetime',
            'effective_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
