<?php

namespace KraftInk\Plugins\Shop\Models\Billing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int $receipt_id
 * @property string|null $address_hash
 * @property bool $automatic
 * @property string $status
 * @property int $attempt
 * @property Carbon|null $queued_at
 * @property Carbon|null $started_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DocumentMessage extends Model
{
    protected $table = 'shop__document_messages';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'receipt_id' => 'integer',
            'automatic' => 'boolean',
            'attempt' => 'integer',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class, 'receipt_id');
    }
}
