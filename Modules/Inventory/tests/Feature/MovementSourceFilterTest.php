<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\Inbound\Models\Inbound;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\Putaway;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class MovementSourceFilterTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;
    protected Location $location;
    protected ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createPrivilegedUser();

        $this->location = Location::where('location_code', 'WH-PUSAT')->first()
            ?? Location::factory()->create([
                'location_code' => 'WH-PUSAT',
                'location_name' => 'Gudang Pusat',
            ]);

        $category = Category::create(['name' => 'Kategori ' . Str::random(4)]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Produk ' . Str::random(4),
            'sku' => 'SKU-' . Str::random(6),
            'status' => 'master',
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'VAR-' . Str::random(6),
        ]);
    }

    public function test_tagihan_filter_excludes_transfer_and_return_putaways(): void
    {
        // 1. Create Inbound PO
        $poInb = Inbound::create([
            'id' => (string) Str::uuid(),
            'transaction_number' => 'INB-PO-TEST-01',
            'type' => 'PURCHASE_ORDER',
            'source_type' => 'purchase_order',
            'location_id' => $this->location->id,
            'expected_date' => now()->toDateString(),
            'created_by' => $this->user->id,
            'status' => 'COMPLETED',
        ]);
        $poPut = Putaway::create([
            'id' => (string) Str::uuid(),
            'putaway_no' => 'PUT-PO-001',
            'source_id' => $poInb->id,
            'source_type' => 'INBOUND',
            'location_id' => $this->location->id,
            'created_by' => $this->user->id,
            'status' => 'COMPLETED',
        ]);
        InventoryMovement::create([
            'id' => (string) Str::uuid(),
            'transaction_number' => 'PUT-PO-001',
            'transaction_date' => now(),
            'location_id' => $this->location->id,
            'item_id' => $this->variant->id,
            'qty' => 10,
            'balance' => 10,
            'created_by' => $this->user->id,
            'source' => 'PUTAWAY_IN',
        ]);

        // 2. Create Inbound Transfer
        $trfInb = Inbound::create([
            'id' => (string) Str::uuid(),
            'transaction_number' => 'TRFI-000000362',
            'type' => 'TRANSIT_IN',
            'source_type' => 'transfer',
            'location_id' => $this->location->id,
            'expected_date' => now()->toDateString(),
            'created_by' => $this->user->id,
            'status' => 'COMPLETED',
        ]);
        $trfPut = Putaway::create([
            'id' => (string) Str::uuid(),
            'putaway_no' => 'PUT-TRF-001',
            'source_id' => $trfInb->id,
            'source_type' => 'INBOUND',
            'location_id' => $this->location->id,
            'created_by' => $this->user->id,
            'status' => 'COMPLETED',
        ]);
        InventoryMovement::create([
            'id' => (string) Str::uuid(),
            'transaction_number' => 'PUT-TRF-001',
            'transaction_date' => now(),
            'location_id' => $this->location->id,
            'item_id' => $this->variant->id,
            'qty' => 500,
            'balance' => 510,
            'created_by' => $this->user->id,
            'source' => 'PUTAWAY_IN',
        ]);

        // Test Filter TAGIHAN -> Only PO Putaway should be returned
        $resTagihan = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/inventory/movements?filter[item_id]={$this->variant->id}&filter[source]=TAGIHAN");

        $resTagihan->assertStatus(200);
        $tagihanTrxNos = collect($resTagihan->json('data'))->pluck('transaction_number')->all();
        $this->assertContains('PUT-PO-001', $tagihanTrxNos);
        $this->assertNotContains('PUT-TRF-001', $tagihanTrxNos, 'Filter Tagihan tidak boleh memuat mutasi Transfer Putaway (TRFI)');

        // Test Filter TRANSFER -> Transfer Putaway should be returned
        $resTransfer = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/inventory/movements?filter[item_id]={$this->variant->id}&filter[source]=TRANSFER");

        $resTransfer->assertStatus(200);
        $transferTrxNos = collect($resTransfer->json('data'))->pluck('transaction_number')->all();
        $this->assertContains('PUT-TRF-001', $transferTrxNos, 'Filter Transfer harus memuat mutasi Transfer Putaway (TRFI)');
        $this->assertNotContains('PUT-PO-001', $transferTrxNos);
    }
}
