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

    public function test_next_day_variants_are_classified_as_express(): void
    {
        $this->assertSame('EXPRESS', $this->service->resolveShipmentType('J&T Express Next-day delivery'));
        $this->assertSame('EXPRESS', $this->service->resolveShipmentType('GoTo Logistics GTL Next-day delivery'));
        $this->assertSame('EXPRESS', $this->service->resolveShipmentType('Next_day'));
    }

    public function test_jtr_abbreviation_is_cargo_across_channels(): void
    {

        $this->assertSame('CARGO', $this->service->resolveShipmentType('JNE Trucking (JTR)'));
        $this->assertSame('CARGO', $this->service->resolveShipmentType('JNE JTR'));
    }

    public function test_borzo_is_classified_instant(): void
    {
        $this->assertSame('INSTANT', $this->service->resolveShipmentType('BORZO'));
    }

    public function test_bluebird_same_day_is_classified_instant(): void
    {

        $this->assertSame('INSTANT', $this->service->resolveShipmentType('Bluebird Kirim'));
        $this->assertSame('bluebird', $this->service->resolveCode('Bluebird Kirim'));
    }

    public function test_alfatrex_does_not_collide_with_rex_courier(): void
    {

        $this->assertSame('alfatrex', $this->service->resolveCode('Alfatrex (Ambil di Alfamart)'));
        $this->assertNotSame('rex', $this->service->resolveCode('Alfatrex (Ambil di Alfamart)'));

        $this->assertSame('rex', $this->service->resolveCode('REX'));
    }

    public function test_indopaket_unifies_across_channels(): void
    {

        $this->assertSame('indopaket', $this->service->resolveCode('Indopaket (Ambil di Indomaret)'));
        $this->assertSame('indopaket', $this->service->resolveCode('Indopaket'));
    }

    public function test_rara_is_classified_instant(): void
    {
        $this->assertSame('INSTANT', $this->service->resolveShipmentType('Rara'));
    }

    public function test_royal_express_unifies_with_rex_code(): void
    {

        $this->assertSame('rex', $this->service->resolveCode('Royal Express'));
        $this->assertSame('rex', $this->service->resolveCode('REX'));

        $this->assertSame('alfatrex', $this->service->resolveCode('Alfatrex (Ambil di Alfamart)'));
    }

    public function test_gojek_and_gosend_unify_to_one_code(): void
    {

        $this->assertSame('gosend', $this->service->resolveCode('Gojek'));
        $this->assertSame('gosend', $this->service->resolveCode('GoSend Instant'));
        $this->assertSame('INSTANT', $this->service->resolveShipmentType('Gojek'));
    }

    public function test_jne_cargo_from_shopee_and_lazada_group_together(): void
    {
        $orders = [
            (object) ['id' => 'S', 'source' => 'shopee', 'shipping_provider' => 'JNE Trucking (JTR)'],
            (object) ['id' => 'L', 'source' => 'lazada', 'shipping_provider' => 'JNE JTR'],
        ];

        $groups = $this->service->groupOrdersForManifest($orders);

        $this->assertArrayHasKey('jne|CARGO', $groups);
        $this->assertCount(2, $groups['jne|CARGO']['orders']);
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

    public function test_authoritative_channel_instant_signal_overrides_name_regex(): void
    {

        $this->assertSame('REGULAR', $this->service->resolveShipmentType('Same Day', false));
        $this->assertSame('REGULAR', $this->service->resolveShipmentType('GoSend Same Day', false));

        $this->assertSame('CARGO', $this->service->resolveShipmentType('Hemat Kargo', false));
        $this->assertSame('EXPRESS', $this->service->resolveShipmentType('Next Day', false));

        $this->assertSame('INSTANT', $this->service->resolveShipmentType('Apapun', true));

        $this->assertSame('INSTANT', $this->service->resolveShipmentType('Same Day', null));
        $this->assertSame('INSTANT', $this->service->resolveShipmentType('Same Day'));
    }

    public function test_manifest_resolution_uses_channel_category_over_courier_mapping(): void
    {
        $mapping = $this->service->record('shopee', 'SPX Same Day', 'SHP-SDAY');
        $mapping->update(['shipment_type' => 'INSTANT']);

        $regular = $this->service->resolveForOrder((object) [
            'source' => 'shopee',
            'shipping_provider' => 'SPX Same Day',
            'channel_instant' => false,
        ]);

        $instant = $this->service->resolveForOrder((object) [
            'source' => 'shopee',
            'shipping_provider' => 'SPX Standard',
            'channel_instant' => true,
        ]);

        $this->assertSame('REGULAR', $regular['shipment_type']);
        $this->assertSame('INSTANT', $instant['shipment_type']);
    }

    public function test_manifest_grouping_honors_stored_resolved_shipment_type(): void
    {

        $orders = [
            (object) ['id' => 'X', 'source' => 'shopee', 'shipping_provider' => 'Same Day', 'resolved_shipment_type' => 'REGULAR'],
        ];

        $groups = $this->service->groupOrdersForManifest($orders);

        $this->assertArrayHasKey('same_day|REGULAR', $groups);
        $this->assertArrayNotHasKey('same_day|INSTANT', $groups);
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

    public function test_goto_logistics_resolves_to_gtl_code(): void
    {
        $this->assertSame('gtl', $this->service->resolveCode('GTL'));
        $this->assertSame('gtl', $this->service->resolveCode('GoTo Logistics'));
        $this->assertSame('gtl', $this->service->resolveCode('GoTo Logistics GTL'));
        $this->assertSame('gtl', $this->service->resolveCode('GoTo Logistics GTL Next-day delivery'));
        $this->assertSame('gtl', $this->service->resolveCode('Tokopedia Kurir Rekomendasi'));
    }

    public function test_additional_courier_aliases(): void
    {
        $this->assertSame('lex', $this->service->resolveCode('LEX'));
        $this->assertSame('lex', $this->service->resolveCode('LEX ID'));
        $this->assertSame('blitz', $this->service->resolveCode('Blitz'));
        $this->assertSame('blitz', $this->service->resolveCode('Blitz-ID Instant'));
        $this->assertSame('blitz', $this->service->resolveCode('PT BLITZ ELECTRIC MOBILITY'));
        $this->assertSame('sentral_cargo', $this->service->resolveCode('Sentral Cargo'));
        $this->assertSame('indah_logistik', $this->service->resolveCode('Indah Logistik'));
    }
}
