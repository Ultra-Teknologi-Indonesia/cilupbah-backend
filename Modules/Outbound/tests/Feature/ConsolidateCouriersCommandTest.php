<?php

namespace Modules\Outbound\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Outbound\Models\Courier;
use Modules\Outbound\Models\CourierChannelMapping;
use Tests\TestCase;

class ConsolidateCouriersCommandTest extends TestCase
{
    use RefreshDatabase;

    private function seedRawCouriers(): void
    {
        foreach (['JNE REG', 'JNE YES', 'JNE OKE', 'JNE Trucking'] as $name) {
            Courier::create(['name' => $name, 'code' => strtoupper(str_replace(' ', '_', $name)), 'is_active' => true]);
        }
        foreach (['Spx', 'SPX Standard', 'SPX Instant', 'SPX Hemat'] as $name) {
            Courier::create(['name' => $name, 'code' => strtoupper(str_replace(' ', '_', $name)), 'is_active' => true]);
        }

        Courier::create(['name' => 'Ambil Sendiri', 'code' => 'AMBIL_SENDIRI', 'is_active' => true]);
    }

    public function test_dry_run_does_not_modify_database(): void
    {
        $this->seedRawCouriers();

        $this->artisan('couriers:consolidate')->assertSuccessful();

        $this->assertSame(9, Courier::where('is_active', true)->count());
        $this->assertSame(0, CourierChannelMapping::count());
    }

    public function test_apply_consolidates_jne_and_spx_variants_into_one_row_each(): void
    {
        $this->seedRawCouriers();

        $this->artisan('couriers:consolidate', ['--apply' => true])->assertSuccessful();

        $this->assertSame(1, Courier::where('code', 'jne')->where('is_active', true)->count());
        $this->assertSame('JNE', Courier::where('code', 'jne')->first()->name);

        $this->assertSame(1, Courier::where('name', 'SPX')->where('is_active', true)->count());

        $this->assertSame(7, Courier::where('is_active', false)->count());
        $this->assertSame(0, Courier::where('name', 'Spx')->count());

        $ambilSendiri = Courier::where('name', 'Ambil Sendiri')->first();
        $this->assertNotNull($ambilSendiri);
        $this->assertTrue((bool) $ambilSendiri->is_active);

        $this->assertSame(7, CourierChannelMapping::where('channel_code', 'legacy')->count());
        $spxInstantMapping = CourierChannelMapping::where('external_name', 'SPX Instant')->first();
        $this->assertNotNull($spxInstantMapping);
        $this->assertSame('SPX', $spxInstantMapping->courier->name);
    }

    public function test_apply_is_idempotent(): void
    {
        $this->seedRawCouriers();

        $this->artisan('couriers:consolidate', ['--apply' => true])->assertSuccessful();
        $this->artisan('couriers:consolidate', ['--apply' => true])->assertSuccessful();

        $this->assertSame(1, Courier::where('code', 'jne')->where('is_active', true)->count());
        $this->assertSame(1, Courier::where('name', 'SPX')->where('is_active', true)->count());
        $this->assertSame(7, CourierChannelMapping::count());
    }
}
