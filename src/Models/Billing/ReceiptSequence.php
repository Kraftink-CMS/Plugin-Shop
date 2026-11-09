<?php

namespace KraftInk\Plugins\Shop\Models\Billing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use KraftInk\Plugins\Shop\Models\Concerns\ReservesAnnualNumbers;

/**
 * @mixin Builder<static>
 *
 * @property int $year
 * @property string|null $number_prefix
 * @property int $next_number
 */
class ReceiptSequence extends Model
{
    use ReservesAnnualNumbers;

    protected $table = 'shop__receipt_sequences';

    protected $guarded = [];

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'year';

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'next_number' => 'integer',
        ];
    }
}
