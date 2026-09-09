<?php

namespace Tests\Unit;

use App\Support\Reports\ReportPeriod;
use PHPUnit\Framework\TestCase;

class ReportPeriodTest extends TestCase
{
    public function test_daily_period_is_the_calendar_day_of_the_reference_date(): void
    {
        $period = ReportPeriod::resolve('daily', '2026-09-08');

        $this->assertSame('daily', $period->period);
        $this->assertSame('2026-09-08 00:00:00', $period->start->toDateTimeString());
        $this->assertSame('2026-09-09 00:00:00', $period->end->toDateTimeString());
        $this->assertSame('September 8, 2026', $period->label);
    }

    public function test_weekly_period_is_the_monday_to_sunday_week_containing_the_reference_date(): void
    {
        // 2026-09-08 is a Tuesday; the ISO week runs Mon 7th → Sun 13th.
        $period = ReportPeriod::resolve('weekly', '2026-09-08');

        $this->assertSame('2026-09-07 00:00:00', $period->start->toDateTimeString());
        $this->assertSame('2026-09-14 00:00:00', $period->end->toDateTimeString());
    }

    public function test_monthly_period_is_the_calendar_month_containing_the_reference_date(): void
    {
        $period = ReportPeriod::resolve('monthly', '2026-09-08');

        $this->assertSame('2026-09-01 00:00:00', $period->start->toDateTimeString());
        $this->assertSame('2026-10-01 00:00:00', $period->end->toDateTimeString());
        $this->assertSame('September 2026', $period->label);
    }

    public function test_an_unknown_period_falls_back_to_daily(): void
    {
        $this->assertSame('daily', ReportPeriod::resolve('yearly', '2026-09-08')->period);
    }
}
