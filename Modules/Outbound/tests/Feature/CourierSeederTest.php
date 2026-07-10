<?php

namespace Modules\Outbound\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Outbound\Database\Seeders\CourierSeeder;
use Modules\Outbound\Models\Courier;
use Tests\TestCase;

class CourierSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_produces_exactly_the_canonical_list(): void
    {
        (new CourierSeeder())->run();

        $seeded = Courier::where('is_active', true)->pluck('name')->sort()->values()->all();
        $expected = collect(CourierSeeder::canonicalNames())->sort()->values()->all();

        $this->assertSame($expected, $seeded);
    }

    public function test_canonical_list_has_no_duplicate_names(): void
    {
        $names = CourierSeeder::canonicalNames();
        $normalized = collect($names)->map(fn (string $n) => mb_strtolower(trim($n)));

        $this->assertSame(
            $normalized->count(),
            $normalized->unique()->count(),
            'Ada nama kurir kanonik yang duplikat (case-insensitive).'
        );
    }

    public function test_seeder_is_idempotent(): void
    {
        (new CourierSeeder())->run();
        $first = Courier::count();

        (new CourierSeeder())->run();
        $second = Courier::count();

        $this->assertSame($first, $second);
    }
}
