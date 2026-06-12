<?php

namespace Modules\Warehouse\Tests\Unit;

use Tests\TestCase;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Modules\Warehouse\Services\LocationBinService;
use Modules\Warehouse\Repositories\LocationBinRepository;
use Modules\Warehouse\Repositories\LocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;

class LocationBinServiceTest extends TestCase
{
    use RefreshDatabase;

    protected LocationBinService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LocationBinService(new LocationBinRepository(), new LocationRepository());
    }

    public function test_create_generates_final_code(): void
    {
        $location = Location::factory()->create();
        
        $data = [
            'location_id' => $location->id,
            'floor_code' => 'L1',
            'row_code' => 'B2',
            'column_code' => 'K3',
            'bin_code' => 'R4',
            'is_inbound' => false,
        ];

        $bin = $this->service->create($data);
        
        $this->assertEquals('L1-B2-K3-R4', $bin->bin_final_code);
    }
    public function test_can_get_bins_by_location(): void
    {
        $location = Location::factory()->create();
        LocationBin::factory()->count(5)->create(['location_id' => $location->id]);
        
        $bins = $this->service->getByLocation($location->id);
        
        $this->assertCount(5, $bins);
    }

    public function test_can_get_bin_by_id(): void
    {
        $location = Location::factory()->create();
        $bin = LocationBin::factory()->create(['location_id' => $location->id]);
        
        $found = $this->service->getById($bin->id);
        
        $this->assertNotNull($found);
        $this->assertEquals($bin->id, $found->id);
    }

    public function test_can_update_bin_recalculates_final_code(): void
    {
        $location = Location::factory()->create();
        $bin = LocationBin::factory()->create([
            'location_id' => $location->id,
            'floor_code' => 'L1',
            'row_code' => 'B1',
            'column_code' => 'K1',
            'bin_code' => 'R1',
        ]);
        
        $result = $this->service->update($bin->id, [
            'floor_code' => 'L2',
        ]);
        
        $this->assertTrue($result);
        
        $updatedBin = $this->service->getById($bin->id);
        $this->assertEquals('L2-B1-K1-R1', $updatedBin->bin_final_code);
    }

    public function test_can_delete_bin(): void
    {
        $location = Location::factory()->create();
        $bin = LocationBin::factory()->create([
            'location_id' => $location->id,
            'is_inbound' => false,
        ]);
        
        $result = $this->service->delete($bin->id);
        
        $this->assertTrue($result);
        $this->assertDatabaseMissing('location_bins', ['id' => $bin->id]);
    }

    public function test_cannot_delete_inbound_bin(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Bin inbound (default) tidak dapat dihapus.');

        $location = Location::factory()->create();
        $bin = LocationBin::factory()->create([
            'location_id' => $location->id,
            'is_inbound' => true,
        ]);
        
        $this->service->delete($bin->id);
    }
}
