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
    public function test_can_get_paginated_locations(): void
    {
        Location::factory()->count(15)->create();
        $paginator = $this->service->getAllPaginated(10);
        
        $this->assertEquals(15, $paginator->total());
        $this->assertCount(10, $paginator->items());
    }

    public function test_can_get_location_by_id(): void
    {
        $location = Location::factory()->create();
        $found = $this->service->getById($location->id);
        
        $this->assertNotNull($found);
        $this->assertEquals($location->id, $found->id);
    }

    public function test_can_update_location(): void
    {
        $location = Location::factory()->create();
        
        $result = $this->service->update($location->id, ['location_name' => 'Updated Name']);
        
        $this->assertTrue($result);
        $this->assertDatabaseHas('locations', ['id' => $location->id, 'location_name' => 'Updated Name']);
    }

    public function test_can_delete_location(): void
    {
        $location = Location::factory()->create();
        
        $result = $this->service->delete($location->id);
        
        $this->assertTrue($result);
        $this->assertDatabaseMissing('locations', ['id' => $location->id]);
    }

    public function test_cannot_delete_location_with_inventory(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Lokasi tidak dapat dihapus karena masih memiliki data stok.');

        $location = Location::factory()->create();
        
        $categoryId = \Illuminate\Support\Facades\DB::table('categories')->insertGetId([
            'name' => 'Test Category',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        $productId = \Illuminate\Support\Facades\DB::table('products')->insertGetId([
            'category_id' => $categoryId,
            'name' => 'Test Product',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \Illuminate\Support\Facades\DB::table('inventories')->insert([
            'location_id' => $location->id,
            'item_id' => $productId,
            'bin_id' => null,
            'batch_no' => 'B001',
            'serial_no' => 'S001',
            'on_hand' => 10,
            'on_order' => 0,
            'reserved' => 0,
            'available' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $this->service->delete($location->id);
        } finally {
            \Illuminate\Support\Facades\DB::table('inventories')->where('location_id', $location->id)->delete();
        }
    }
}
