<?php

namespace Modules\Warehouse\Tests\Unit;

use Tests\TestCase;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Modules\Warehouse\Services\LocationService;
use Modules\Warehouse\Repositories\LocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;

class LocationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected LocationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LocationService(new LocationRepository(), new \Modules\Warehouse\Repositories\LocationBinRepository());
    }

    public function test_create_location_generates_default_bin(): void
    {
        $data = [
            'location_code' => 'TEST-SRV',
            'location_name' => 'Test Service',
            'location_type' => 'warehouse',
        ];

        $location = $this->service->create($data);

        $this->assertDatabaseHas('locations', [
            'id' => $location->id,
            'location_code' => 'TEST-SRV'
        ]);

        $this->assertDatabaseHas('location_bins', [
            'location_id' => $location->id,
            'bin_final_code' => 'DEFAULT',
            'is_inbound' => true
        ]);
        
        $this->assertEquals(1, LocationBin::where('location_id', $location->id)->count());
    }
}
