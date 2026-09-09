<?php

namespace App\Support;

/**
 * Philippine peso denomination maths for cash counts and expense
 * breakdowns — BITS `bus_day_cash_counts.total_amount` (docs/MIGRATION_MAP.md
 * §4.4):
 *
 *   total = 1000·q1000 + 500·q500 + 200·q200 + 100·q100
 *         +   50·q50   +  20·q20  +  10·q10  +   5·q5   + 1·q1
 */
class Denominations
{
    /** Denomination value => count field name, high to low. */
    public const FIELDS = [
        1000 => 'q1000',
        500 => 'q500',
        200 => 'q200',
        100 => 'q100',
        50 => 'q50',
        20 => 'q20',
        10 => 'q10',
        5 => 'q5',
        1 => 'q1',
    ];

    /**
     * @param  array<string, int|string|null>  $counts  keyed by q1000..q1
     */
    public static function total(array $counts): int
    {
        $total = 0;

        foreach (self::FIELDS as $value => $field) {
            $total += $value * (int) ($counts[$field] ?? 0);
        }

        return $total;
    }

    /**
     * The count fields normalised to non-negative ints (missing => 0).
     *
     * @param  array<string, int|string|null>  $counts
     * @return array<string, int>
     */
    public static function normalize(array $counts): array
    {
        $out = [];

        foreach (self::FIELDS as $field) {
            $out[$field] = max(0, (int) ($counts[$field] ?? 0));
        }

        return $out;
    }
}
