<?php

namespace Modules\Outbound\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Outbound\Database\Seeders\CourierSeeder;
use Modules\Outbound\Models\Courier;
use Tests\TestCase;

class SyncMasterCouriersCommandTest extends TestCase
{
    use RefreshDatabase;

    private function seedMessyMaster(): void
    {

        Courier::create(['name' => 'jne', 'code' => 'JNE_OLD', 'is_active' => true]);       
        Courier::create(['name' => 'SPX', 'code' => 'SPX', 'is_active' => false]);          
        Courier::create(['name' => 'J&T', 'code' => 'JNT', 'is_active' => true]);
        Courier::create(['name' => 'J&T Cargo', 'code' => 'JNT_CARGO', 'is_active' => true]);

        Courier::create(['name' => 'ABC TRANSPORT', 'code' => 'ABC_TRANSPORT', 'is_active' => true]);
        Courier::create(['name' => 'kurir 4848', 'code' => 'KURIR_4848', 'is_active' => true]);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->seedMessyMaster();
        $before = Courier::get()->map->only(['name', 'is_active'])->toArray();

        $this->artisan('couriers:sync-master')->assertSuccessful();

        $after = Courier::get()->map->only(['name', 'is_active'])->toArray();
        $this->assertEquals($before, $after);
    }

    public function test_apply_makes_master_exactly_the_canonical_list(): void
    {
        $this->seedMessyMaster();

        $this->artisan('couriers:sync-master', ['--apply' => true])->assertSuccessful();

        $active = Courier::where('is_active', true)->pluck('name')->sort()->values()->all();
        $expected = collect(CourierSeeder::canonicalNames())->sort()->values()->all();
        $this->assertSame($expected, $active);

        $this->assertSame(1, Courier::where('name', 'JNE')->where('is_active', true)->count());
        $this->assertSame(1, Courier::where('name', 'SPX')->where('is_active', true)->count());

        $this->assertSame(1, Courier::where('name', 'J&T')->where('is_active', true)->count());
        $this->assertSame(1, Courier::where('name', 'J&T Cargo')->where('is_active', true)->count());

        $this->assertSame(1, Courier::where('name', 'ABC TRANSPORT')->where('is_active', false)->count());
        $this->assertSame(1, Courier::where('name', 'kurir 4848')->where('is_active', false)->count());
    }

    public function test_apply_is_idempotent(): void
    {
        $this->seedMessyMaster();

        $this->artisan('couriers:sync-master', ['--apply' => true])->assertSuccessful();
        $firstActive = Courier::where('is_active', true)->count();

        $this->artisan('couriers:sync-master', ['--apply' => true])
            ->expectsOutputToContain('Master kurir sudah persis sesuai daftar kanonik')
            ->assertSuccessful();

        $this->assertSame($firstActive, Courier::where('is_active', true)->count());
    }
}
