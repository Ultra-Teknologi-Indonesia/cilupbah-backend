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

    public function test_spx_variants_all_resolve_to_one_canonical_courier(): void
    {
        $standard = $this->service->record('shopee', 'SPX Standard', 'SHP-STD');
        $instant = $this->service->record('shopee', 'SPX Instant', 'SHP-INST');
        $sameday = $this->service->record('tiktok', 'SPX Same Day', 'TT-SDAY');

        $this->assertSame('spx', $standard->courier->code);
        $this->assertSame($standard->courier_id, $instant->courier_id);
        $this->assertSame($standard->courier_id, $sameday->courier_id);
        $this->assertSame(1, Courier::where('code', 'spx')->count());

        // Kecepatan pengiriman tetap terekam lewat shipment_type, bukan lewat
        // identitas kurir yang terpisah.
        $this->assertSame('REGULAR', $standard->shipment_type);
        $this->assertSame('INSTANT', $instant->shipment_type);
        $this->assertSame('INSTANT', $sameday->shipment_type);
    }

    public function test_most_specific_keyword_wins(): void
    {
        $this->assertSame('spx', $this->service->resolveCode('SPX Instant - 2 Jam'));
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
        $this->assertArrayHasKey('spx|INSTANT', $groups);
        $this->assertCount(1, $groups['spx|INSTANT']['orders']);
    }
}
