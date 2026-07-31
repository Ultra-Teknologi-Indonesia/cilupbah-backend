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

        $this->assertSame('REGULAR', $standard->shipment_type);
        $this->assertSame('INSTANT', $instant->shipment_type);
        $this->assertSame('INSTANT', $sameday->shipment_type);
    }

    public function test_most_specific_keyword_wins(): void
    {
        $this->assertSame('spx', $this->service->resolveCode('SPX Instant - 2 Jam'));
        $this->assertSame('spx', $this->service->resolveCode('SPX Standard'));
    }

    public function test_messy_channel_strings_resolve_to_correct_courier(): void
    {

        $this->assertSame('jnt', $this->service->resolveCode('Drop-off: LEX ID, Delivery: J&T'));
        $this->assertSame('jne', $this->service->resolveCode('Drop-off: JNE Cashless, Delivery: JNE Cashless'));

        $this->assertSame('jnt', $this->service->resolveCode('TT Virtual# JNT express'));
        $this->assertSame('jnt', $this->service->resolveCode("Sandbox-J&T Express(Don't modify)"));
        $this->assertSame('spx', $this->service->resolveCode('SPX Instant Prioritas'));
    }

    public function test_lazada_drop_off_courier_does_not_hijack_delivery_courier(): void
    {

        $this->assertSame('jnt', $this->service->resolveCode('Drop-off: LEX ID, Delivery: J&T'));
        $this->assertNotSame('lex', $this->service->resolveCode('Drop-off: LEX ID, Delivery: J&T'));
    }

    public function test_instant_detected_by_brand_name_not_only_keyword(): void
    {

        $this->assertSame('INSTANT', $this->service->resolveShipmentType('GrabExpress'));
        $this->assertSame('INSTANT', $this->service->resolveShipmentType('GoSend'));
        $this->assertSame('INSTANT', $this->service->resolveShipmentType('Drop-off: Grab-ID, Delivery: Grab-ID'));

        $this->assertSame('REGULAR', $this->service->resolveShipmentType('J&T Express'));
    }

    public function test_empty_provider_resolves_to_empty_code(): void
    {
        $this->assertSame('', $this->service->resolveCode(''));
        $this->assertSame('', $this->service->resolveCode('   '));
    }

    public function test_resolve_courier_id_matches_master_regardless_of_code_scheme(): void
    {

        $jne = Courier::create(['name' => 'JNE', 'code' => 'JNE', 'is_active' => true]);
        $jnt = Courier::create(['name' => 'J&T', 'code' => 'jnt', 'is_active' => true]);

        $this->assertSame($jne->id, $this->service->resolveCourierId('Drop-off: JNE Cashless, Delivery: JNE Cashless'));
        $this->assertSame($jnt->id, $this->service->resolveCourierId('Drop-off: LEX ID, Delivery: J&T'));
        $this->assertSame($jnt->id, $this->service->resolveCourierId('TT Virtual# J&T Express'));
    }

    public function test_resolve_courier_id_returns_null_without_confident_match(): void
    {
        Courier::create(['name' => 'JNE', 'code' => 'JNE', 'is_active' => true]);

        $this->assertNull($this->service->resolveCourierId('Delivered by Seller'));
        $this->assertNull($this->service->resolveCourierId('AMBIL_SENDIRI'));
        $this->assertNull($this->service->resolveCourierId(''));
        $this->assertNull($this->service->resolveCourierId(null));
    }

    public function test_resolve_courier_id_ignores_inactive_master_courier(): void
    {
        Courier::create(['name' => 'JNE', 'code' => 'JNE', 'is_active' => false]);

        $this->assertNull($this->service->resolveCourierId('JNE Cashless'));
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
