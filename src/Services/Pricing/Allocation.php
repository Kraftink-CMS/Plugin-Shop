<?php

namespace KraftInk\Plugins\Shop\Services\Pricing;

use InvalidArgumentException;

class Allocation
{
    public static function proportional(int $amount, array $weights): array
    {
        $total = array_sum($weights);
        $result = array_fill_keys(array_keys($weights), 0);
        if (
            $amount < 0 ||
            $amount > $total ||
            $total > 99999999 ||
            array_filter($weights, fn ($weight) => !is_int($weight) || $weight < 0)
        ) {
            throw new InvalidArgumentException('Invalid monetary allocation.');
        }
        if ($amount === 0) {
            return $result;
        }
        foreach ($weights as $key => $weight) {
            $result[$key] = intdiv($amount * $weight, $total);
        }
        $remaining = $amount - array_sum($result);
        while ($remaining-- > 0) {
            $best = null;
            foreach ($weights as $key => $weight) {
                if ($best === null || $weight * ($result[$best] + 1) > $weights[$best] * ($result[$key] + 1)) {
                    $best = $key;
                }
            }
            $result[$best]++;
        }

        return $result;
    }
}
