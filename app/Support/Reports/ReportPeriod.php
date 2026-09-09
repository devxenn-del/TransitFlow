<?php

namespace App\Support\Reports;

use Illuminate\Support\Carbon;

/**
 * Resolves a `daily` / `weekly` / `monthly` reporting window from a single
 * reference date — the shared date-range logic behind every income report.
 *
 * Ported verbatim from BITS `App\BusIncomeReport::resolveRange()`
 * (docs/MIGRATION_MAP.md §J):
 *
 * - **daily**   — the calendar day of the reference date.
 * - **weekly**  — the Monday–Sunday week containing it (ISO week; Monday start).
 * - **monthly** — the calendar month containing it.
 *
 * `start` is inclusive, `end` is exclusive (the next day/week/month start), so
 * a query filters `>= start AND < end`.
 */
class ReportPeriod
{
    public const PERIODS = ['daily', 'weekly', 'monthly'];

    private function __construct(
        public readonly string $period,
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly string $label,
    ) {}

    public static function isValidPeriod(string $period): bool
    {
        return in_array($period, self::PERIODS, true);
    }

    /**
     * @param  string  $period  one of self::PERIODS; anything else falls back to `daily`.
     * @param  string|Carbon|null  $referenceDate  the date the window is built around (default: today).
     */
    public static function resolve(string $period, string|Carbon|null $referenceDate = null): self
    {
        $period = self::isValidPeriod($period) ? $period : 'daily';
        $reference = $referenceDate instanceof Carbon
            ? $referenceDate->copy()
            : Carbon::parse($referenceDate ?: 'now');

        return match ($period) {
            'weekly' => self::week($reference),
            'monthly' => self::month($reference),
            default => self::day($reference),
        };
    }

    private static function day(Carbon $reference): self
    {
        $start = $reference->copy()->startOfDay();

        return new self('daily', $start, $start->copy()->addDay(), $start->format('F j, Y'));
    }

    private static function week(Carbon $reference): self
    {
        $monday = $reference->copy()->startOfWeek(Carbon::MONDAY);
        $sunday = $monday->copy()->addDays(6);

        return new self(
            'weekly',
            $monday,
            $monday->copy()->addDays(7),
            $monday->format('M j').' – '.$sunday->format('M j, Y'),
        );
    }

    private static function month(Carbon $reference): self
    {
        $start = $reference->copy()->startOfMonth();

        return new self('monthly', $start, $start->copy()->addMonth(), $start->format('F Y'));
    }

    /**
     * @return array{period: string, range_label: string, range_start: string, range_end: string}
     */
    public function toArray(): array
    {
        return [
            'period' => $this->period,
            'range_label' => $this->label,
            'range_start' => $this->start->toDateTimeString(),
            'range_end' => $this->end->toDateTimeString(),
        ];
    }
}
