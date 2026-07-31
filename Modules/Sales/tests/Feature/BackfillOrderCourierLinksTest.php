<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Outbound\Models\Courier;
use Tests\TestCase;

class BackfillOrderCourierLinksTest extends TestCase
{
    use RefreshDatabase;

    private function seedLocation(): string
    {
        $id = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $id,
            'location_code' => 'LOC-BF-' . substr($id, 0, 6),
            'location_name' => 'Gudang BF',
            'location_type' => 'WAREHOUSE',
            'is_warehouse' => true,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    private function seedOrder(string $locationId, ?string $provider): string
    {
        $id = Str::uuid()->toString();
        DB::table('sales_orders')->insert([
            'id' => $id,
            'salesorder_no' => 'SO-BF-' . substr($id, 0, 6),
            'customer_name' => 'Buyer',
            'source' => null,
            'location_id' => $locationId,
            'status' => 'packed',
            'is_canceled' => false,
            'shipping_provider' => $provider,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    public function test_apply_sets_courier_id_and_type_and_skips_unmatched_and_self_pickup(): void
    {
        $jne = Courier::create(['name' => 'JNE', 'code' => 'JNE', 'is_active' => true]);
        $loc = $this->seedLocation();

        $matched = $this->seedOrder($loc, 'Drop-off: JNE Cashless, Delivery: JNE Cashless');
        $noMaster = $this->seedOrder($loc, 'SPX Instant'); // tak ada SPX di master -> courier null, tipe INSTANT
        $selfPickup = $this->seedOrder($loc, null);        // tanpa kurir -> dilewati

        $this->artisan('couriers:backfill-order-links', ['--apply' => true])->assertSuccessful();

        $r1 = DB::table('sales_orders')->where('id', $matched)->first();
        $this->assertSame($jne->id, $r1->courier_id);
        $this->assertSame('REGULAR', $r1->resolved_shipment_type);

        $r2 = DB::table('sales_orders')->where('id', $noMaster)->first();
        $this->assertNull($r2->courier_id);
        $this->assertSame('INSTANT', $r2->resolved_shipment_type);

        $r3 = DB::table('sales_orders')->where('id', $selfPickup)->first();
        $this->assertNull($r3->courier_id);
        $this->assertNull($r3->resolved_shipment_type);
    }

    public function test_dry_run_changes_nothing(): void
    {
        Courier::create(['name' => 'JNE', 'code' => 'JNE', 'is_active' => true]);
        $loc = $this->seedLocation();
        $order = $this->seedOrder($loc, 'JNE Cashless');

        $this->artisan('couriers:backfill-order-links')->assertSuccessful();

        $row = DB::table('sales_orders')->where('id', $order)->first();
        $this->assertNull($row->courier_id);
        $this->assertNull($row->resolved_shipment_type);
    }
}
