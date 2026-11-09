<?php

namespace KraftInk\Plugins\Shop\Models\Catalog;

use Illuminate\Database\Eloquent\Builder;
use App\Models\Servers\Server;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @mixin Builder<static>
 *
 * @property int $product_id
 * @property int $server_id
 * @property-read Server|null $server
 */
class ProductServer extends Pivot
{
    protected $table = 'shop__product_servers';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'server_id' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }
}
