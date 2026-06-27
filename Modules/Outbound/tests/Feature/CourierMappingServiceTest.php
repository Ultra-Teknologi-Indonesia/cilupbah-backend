<?php

namespace Modules\Outbound\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Outbound\Models\Courier;
use Modules\Outbound\Models\CourierChannelMapping;
use Modules\Outbound\Services\CourierMappingService;
use Tests\TestCase;

class CourierMappingServiceTest extends TestCase
{
    use RefreshDatabase;

    private CourierMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CourierMappingService::class);
    }

    public function test_same_courier_from_different_channels_maps_to_one_canonical_courier(): void
    {
        $shopee = $this->service->record('shopee', 'J&T Express', 'SHP-1');
        $tiktok = $this->service->record('tiktok', 'JNT express', 'TT-1');

        $this->assertNotNull($shopee);
        $this->assertNotNull($tiktok);
        $this->assertSame($shopee->courier_id, $tiktok->courier_id);
        $this->assertSame('jnt', $shopee->courier->code);
        $this->assertSame(1, Courier::where('code', 'jnt')->count());
    }

    public function test_brand_name_express_is_not_treated_as_express_tier(): void
    {
        $this->assertSame('REGULAR', $this->service->resolveShipmentType('J&T Express'));
        $this->assertSame('REGULAR', $this->service->resolveShipmentType('JNE Express'));
        $this->assertSame('INSTANT', $this->service->resolveShipmentType('SPX Instant'));
        $this->assertSame('INSTANT', $this->service->resolveShipmentType('GrabExpress - Same Day'));
        $this->assertSame('CARGO', $this->service->resolveShipmentType('JNE Cargo'));
    }

    public function test_verified_mapping_is_not_overwritten_on_resync(): void
    {
        $mapping = $this->service->record('shopee', 'Mystery Courier', 'X-1');
        $mapping->update(['shipment_type' => 'INSTANT', 'is_verified' => true]);

        $this->service->record('shopee', 'Mystery Courier', 'X-1');

        $this->assertSame('INSTANT', $mapping->fresh()->shipment_type);
        $this->assertSame(1, CourierChannelMapping::where('external_name', 'Mystery Courier')->count());
    }

    public function test_instant_brand_is_typed_instant_in_master_couriers(): void
    {
        $mapping = $this->service->record('shopee', 'SPX Instant', 'SHP-INST');

        $this->assertSame('spx_instant', $mapping->courier->code);
        $this->assertSame('INSTANT', $mapping->courier->type);
        $this->assertTrue(
            Courier::where('type', 'INSTANT')->where('code', 'spx_instant')->exists()
        );
    }

    public function test_existing_regular_instant_courier_is_promoted_to_instant(): void
    {
        $courier = Courier::create(['name' => 'SPX Instant', 'code' => 'spx_instant', 'type' => 'REGULAR']);

        $this->service->record('shopee', 'SPX Instant', 'SHP-INST');

        $this->assertSame('INSTANT', $courier->fresh()->type);
    }

    public function test_most_specific_keyword_wins(): void
    {

        $this->assertSame('spx_instant', $this->service->resolveCode('SPX Instant - 2 Jam'));
        $this->assertSame('spx', $this->service->resolveCode('SPX Standard'));
    }

    public function test_groups_orders_across_channels_into_one_manifest_bucket(): void
    {
        $orders = [
            (object) ['id' => 'A', 'source' => 'shopee', 'shipping_provider' => 'J&T Express'],
            (object) ['id' => 'B', 'source' => 'tiktok', 'shipping_provider' => 'JNT express'],
            (object) ['id' => 'C', 'source' => 'shopee', 'shipping_provider' => 'SPX Instant'],
        ];

        $groups = $this->service->groupOrdersForManifest($orders);

        $this->assertArrayHasKey('jnt|REGULAR', $groups);
        $this->assertCount(2, $groups['jnt|REGULAR']['orders']);
        $this->assertArrayHasKey('spx_instant|INSTANT', $groups);
        $this->assertCount(1, $groups['spx_instant|INSTANT']['orders']);
    }
}
