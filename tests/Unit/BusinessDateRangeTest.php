<?php

namespace Tests\Unit;

use App\Support\BusinessDateRange;
use Tests\TestCase;

class BusinessDateRangeTest extends TestCase
{
    public function test_date_range_covers_complete_wib_calendar_days(): void
    {
        config(['app.business_timezone' => 'Asia/Jakarta']);

        [$from, $to] = BusinessDateRange::bounds('2026-09-17', '2026-09-18');

        $this->assertSame('2026-09-16T17:00:00+00:00', $from?->toIso8601String());
        $this->assertSame('2026-09-18T17:00:00+00:00', $to?->toIso8601String());
    }

    public function test_end_boundary_is_exclusive_for_the_next_wib_day(): void
    {
        config(['app.business_timezone' => 'Asia/Jakarta']);

        $end = BusinessDateRange::endExclusive('2026-09-17');

        $this->assertTrue($end?->equalTo('2026-09-17 17:00:00 UTC'));
    }
}
