<?php

namespace KraftInk\Plugins\Shop\Models\Concerns;

use Illuminate\Support\Facades\DB;

trait ReservesAnnualNumbers
{
    public static function reserveNumber(int $year, string $prefix): array
    {
        return DB::transaction(function () use ($year, $prefix) {
            static::query()->insertOrIgnore(['year' => $year, 'next_number' => 1, 'number_prefix' => $prefix]);
            $sequence = static::query()->whereKey($year)->lockForUpdate()->firstOrFail();
            if (!$sequence->number_prefix) {
                $sequence->update(['number_prefix' => $prefix]);
            }
            $number = $sequence->next_number;
            $sequence->increment('next_number');

            return ['prefix' => $sequence->number_prefix, 'number' => $number];
        }, 3);
    }
}
