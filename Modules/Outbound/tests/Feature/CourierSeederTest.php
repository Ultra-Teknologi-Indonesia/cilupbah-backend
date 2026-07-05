<?php

namespace Modules\Outbound\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Outbound\Database\Seeders\CourierSeeder;
use Modules\Outbound\Models\Courier;
use Modules\Outbound\Services\CourierMappingService;
use Tests\TestCase;

/**
 * Regresi untuk PLANNING-KONSOLIDASI-KURIR.md — memastikan CourierSeeder tidak
 * lagi menghasilkan baris ganda per-varian-layanan (mis. "JNE REG"/"JNE YES").
 * Kalau test ini gagal, kemungkinan ada nama baru masuk ke courierNames() yang
 * seharusnya digabung ke kurir kanonik yang sudah ada.
 */
class CourierSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_does_not_produce_duplicate_couriers_per_brand(): void
    {
        (new CourierSeeder())->run();

        $mapper = app(CourierMappingService::class);
        $duplicates = Courier::where('is_active', true)
            ->get()
            ->groupBy(fn (Courier $c) => $mapper->resolveCode($c->name))
            ->filter(fn ($rows) => $rows->count() > 1);

        $this->assertTrue(
            $duplicates->isEmpty(),
            'Ditemukan kurir yang seharusnya sudah digabung: ' . $duplicates->map(
                fn ($rows, $code) => "{$code}: " . $rows->pluck('name')->implode(', ')
            )->implode(' | ')
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
