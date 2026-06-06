<?php

namespace Modules\Warehouse\Tests\Unit;

use Tests\TestCase;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Modules\Warehouse\Services\LocationBinService;
use Modules\Warehouse\Repositories\LocationBinRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;

class LocationBinServiceTest extends TestCase
{
    use RefreshDatabase;

    protected LocationBinService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LocationBinService(new LocationBinRepository());
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
}
