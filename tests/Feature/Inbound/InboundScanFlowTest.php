<?php

namespace Tests\Feature\Inbound;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Inbound\Models\Inbound;
use Modules\Inbound\Models\InboundAssignment;
use App\Models\User;
use Tests\TestCase;

class InboundScanFlowTest extends TestCase
{
    use RefreshDatabase;

    private Location $warehouse;
    private LocationBin $inboundBin;
    private LocationBin $storageBin;
    private Product $product;
    private ProductVariant $variant;
    private User $admin;
    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['name' => 'Admin Gudang']);
        $this->worker = User::factory()->create(['name' => 'Pekerja Gudang']);

        $this->actingAs($this->admin, 'sanctum');
        $this->seedTestData();
    }

    private function seedTestData(): void
    {
        $this->warehouse = Location::create([
            'location_code' => 'WH-01',
            'location_name' => 'Gudang Utama',
            'location_type' => 'warehouse',
            'is_warehouse'  => true,
            'is_active'     => true,
        ]);

        $this->inboundBin = LocationBin::create([
            'location_id'    => $this->warehouse->id,
            'floor_code'     => 'F1',
            'row_code'       => 'R0',
            'column_code'    => 'C0',
            'bin_code'       => 'INBOUND',
            'bin_final_code' => 'F1-R0-C0-INBOUND',
            'max_qty'        => 0,
            'is_inbound'     => true,
        ]);

        $this->storageBin = LocationBin::create([
            'location_id'    => $this->warehouse->id,
            'floor_code'     => 'F1',
            'row_code'       => 'R1',
            'column_code'    => 'C1',
            'bin_code'       => 'B1',
            'bin_final_code' => 'F1-R1-C1-B1',
            'max_qty'        => 500,
            'is_inbound'     => false,
        ]);

        $categoryId = \DB::table('categories')->insertGetId([
            'name'       => 'Elektronik',
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->product = Product::create([
            'sku'         => 'LAPTOP-001',
            'category_id' => $categoryId,
            'name'        => 'Laptop ASUS VivoBook',
            'is_active'   => true,
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku'        => 'LAPTOP-001-8GB',
            'sell_price' => 7500000,
            'is_active'  => true,
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // STEP 1: CREATE INBOUND → UUID PK = QR CODE
    // ═══════════════════════════════════════════════════════════

    public function test_create_inbound_generates_uuid_per_item(): void
    {
        $response = $this->postJson('/api/v1/inbounds', [
            'location_id'   => $this->warehouse->id,
            'type'          => 'PURCHASE_ORDER',
            'expected_date' => now()->addDays(3)->toDateString(),
            'created_by'    => 'admin',
            'items'         => [
                ['item_id' => $this->variant->id, 'expected_qty' => 20],
            ],
        ]);

        $response->assertStatus(201);

        $itemId = $response->json('data.items.0.id');
        $this->assertNotNull($itemId);
        $this->assertTrue(\Ramsey\Uuid\Uuid::isValid($itemId));
    }

    // ═══════════════════════════════════════════════════════════
    // STEP 2: ADMIN ASSIGN TO WORKER
    // ═══════════════════════════════════════════════════════════

    public function test_admin_assigns_inbound_to_worker(): void
    {
        $inbound = $this->createDraftInbound();

        $response = $this->postJson("/api/v1/inbounds/{$inbound->id}/assign", [
            'assigned_to' => $this->worker->id,
            'notes'       => 'Prioritas tinggi',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.assigned_to', $this->worker->id)
            ->assertJsonPath('data.assigned_by', $this->admin->id)
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.notes', 'Prioritas tinggi');
    }

    public function test_get_assignments_for_inbound(): void
    {
        $inbound = $this->createDraftInbound();

        $this->postJson("/api/v1/inbounds/{$inbound->id}/assign", [
            'assigned_to' => $this->worker->id,
        ]);

        $response = $this->getJson("/api/v1/inbounds/{$inbound->id}/assignments");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals($this->worker->id, $response->json('data.0.assigned_to'));
    }

    public function test_cannot_assign_completed_inbound(): void
    {
        $inbound = $this->createFullyCompletedInbound();

        $response = $this->postJson("/api/v1/inbounds/{$inbound->id}/assign", [
            'assigned_to' => $this->worker->id,
        ]);

        $response->assertStatus(500);
    }

    // ═══════════════════════════════════════════════════════════
    // STEP 3: WORKER SEES ASSIGNMENTS & STARTS
    // ═══════════════════════════════════════════════════════════

    public function test_worker_sees_my_assignments(): void
    {
        $inbound = $this->createDraftInbound();

        $this->postJson("/api/v1/inbounds/{$inbound->id}/assign", [
            'assigned_to' => $this->worker->id,
        ]);

        $this->actingAs($this->worker, 'sanctum');

        $response = $this->getJson('/api/v1/inbounds/my-assignments');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_worker_filters_assignments_by_status(): void
    {
        $inbound = $this->createDraftInbound();

        $this->postJson("/api/v1/inbounds/{$inbound->id}/assign", [
            'assigned_to' => $this->worker->id,
        ]);

        $this->actingAs($this->worker, 'sanctum');

        $pending = $this->getJson('/api/v1/inbounds/my-assignments?status=PENDING');
        $pending->assertOk();
        $this->assertCount(1, $pending->json('data'));

        $inProgress = $this->getJson('/api/v1/inbounds/my-assignments?status=IN_PROGRESS');
        $inProgress->assertOk();
        $this->assertCount(0, $inProgress->json('data'));
    }

    public function test_worker_starts_assignment(): void
    {
        $inbound = $this->createDraftInbound();

        $assignResponse = $this->postJson("/api/v1/inbounds/{$inbound->id}/assign", [
            'assigned_to' => $this->worker->id,
        ]);

        $assignmentId = $assignResponse->json('data.id');

        $this->actingAs($this->worker, 'sanctum');

        $response = $this->postJson("/api/v1/inbounds/assignments/{$assignmentId}/start");

        $response->assertOk()
            ->assertJsonPath('data.status', 'IN_PROGRESS');
        $this->assertNotNull($response->json('data.started_at'));
    }

    public function test_other_worker_cannot_start_assignment(): void
    {
        $inbound = $this->createDraftInbound();

        $assignResponse = $this->postJson("/api/v1/inbounds/{$inbound->id}/assign", [
            'assigned_to' => $this->worker->id,
        ]);

        $assignmentId = $assignResponse->json('data.id');

        $otherWorker = User::factory()->create();
        $this->actingAs($otherWorker, 'sanctum');

        $response = $this->postJson("/api/v1/inbounds/assignments/{$assignmentId}/start");

        $response->assertStatus(500);
    }

    // ═══════════════════════════════════════════════════════════
    // STEP 4: QR SCAN LOOKUP (UUID = PK)
    // ═══════════════════════════════════════════════════════════

    public function test_scan_qr_returns_item_details(): void
    {
        $inbound = $this->createDraftInbound();
        $itemId = \DB::table('inbound_items')
            ->where('inbound_id', $inbound->id)->first()->id;

        $response = $this->getJson("/api/v1/inbounds/scan/{$itemId}");

        $response->assertOk()
            ->assertJsonPath('data.id', $itemId)
            ->assertJsonPath('data.item_id', $this->variant->id);
    }

    public function test_scan_invalid_qr_returns_404(): void
    {
        $fakeUuid = '00000000-0000-0000-0000-000000000000';

        $response = $this->getJson("/api/v1/inbounds/scan/{$fakeUuid}");

        $response->assertStatus(404);
    }

    // ═══════════════════════════════════════════════════════════
    // STEP 5: SCAN PUTAWAY → UPDATE INVENTORY
    // ═══════════════════════════════════════════════════════════

    public function test_scan_putaway_updates_inventory(): void
    {
        $inbound = $this->createReceivedInbound();
        $itemId = \DB::table('inbound_items')
            ->where('inbound_id', $inbound->id)->first()->id;

        $this->actingAs($this->worker, 'sanctum');

        $response = $this->postJson('/api/v1/inbounds/scan-putaway', [
            'inbound_item_id' => $itemId,
            'bin_id'          => $this->storageBin->id,
            'qty'             => 20,
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('inventories', [
            'item_id'     => $this->variant->id,
            'location_id' => $this->warehouse->id,
            'bin_id'      => $this->storageBin->id,
        ]);

        $inventory = \DB::table('inventories')
            ->where('item_id', $this->variant->id)
            ->where('bin_id', $this->storageBin->id)
            ->first();
        $this->assertEquals(20, $inventory->on_hand);
    }

    public function test_scan_putaway_cannot_exceed_pending_qty(): void
    {
        $inbound = $this->createReceivedInbound();
        $itemId = \DB::table('inbound_items')
            ->where('inbound_id', $inbound->id)->first()->id;

        $response = $this->postJson('/api/v1/inbounds/scan-putaway', [
            'inbound_item_id' => $itemId,
            'bin_id'          => $this->storageBin->id,
            'qty'             => 999,
        ]);

        $response->assertStatus(500);
    }

    public function test_scan_putaway_partial_then_complete(): void
    {
        $inbound = $this->createReceivedInbound();
        $itemId = \DB::table('inbound_items')
            ->where('inbound_id', $inbound->id)->first()->id;

        $this->postJson('/api/v1/inbounds/scan-putaway', [
            'inbound_item_id' => $itemId,
            'bin_id'          => $this->storageBin->id,
            'qty'             => 10,
        ])->assertOk();

        $inbound->refresh();
        $this->assertEquals(Inbound::STATUS_PUTAWAY_IN_PROGRESS, $inbound->status);

        $this->postJson('/api/v1/inbounds/scan-putaway', [
            'inbound_item_id' => $itemId,
            'bin_id'          => $this->storageBin->id,
            'qty'             => 10,
        ])->assertOk();

        $inbound->refresh();
        $this->assertEquals(Inbound::STATUS_COMPLETED, $inbound->status);
    }

    // ═══════════════════════════════════════════════════════════
    // FULL FLOW E2E
    // ═══════════════════════════════════════════════════════════

    public function test_full_flow_create_assign_scan_putaway(): void
    {
        // 1. Admin creates inbound
        $createResponse = $this->postJson('/api/v1/inbounds', [
            'location_id'   => $this->warehouse->id,
            'type'          => 'PURCHASE_ORDER',
            'expected_date' => now()->addDays(3)->toDateString(),
            'created_by'    => 'admin',
            'items'         => [
                ['item_id' => $this->variant->id, 'expected_qty' => 10],
            ],
        ]);

        $createResponse->assertStatus(201);
        $inboundId = $createResponse->json('data.id');
        $itemUuid = $createResponse->json('data.items.0.id');
        $this->assertNotNull($itemUuid);
        $this->assertTrue(\Ramsey\Uuid\Uuid::isValid($itemUuid));

        // 2. Admin assigns to worker
        $assignResponse = $this->postJson("/api/v1/inbounds/{$inboundId}/assign", [
            'assigned_to' => $this->worker->id,
            'notes'       => 'Handle laptop batch',
        ]);
        $assignResponse->assertStatus(201);
        $assignmentId = $assignResponse->json('data.id');

        // 3. Worker starts assignment
        $this->actingAs($this->worker, 'sanctum');

        $this->postJson("/api/v1/inbounds/assignments/{$assignmentId}/start")
            ->assertOk()
            ->assertJsonPath('data.status', 'IN_PROGRESS');

        // 4. Worker scans QR (UUID = PK) to see item details
        $scanResponse = $this->getJson("/api/v1/inbounds/scan/{$itemUuid}");
        $scanResponse->assertOk()
            ->assertJsonPath('data.id', $itemUuid)
            ->assertJsonPath('data.expected_qty', 10);

        // 5. Admin receives the goods first (simulate dock receipt)
        $this->actingAs($this->admin, 'sanctum');

        $this->postJson("/api/v1/inbounds/{$inboundId}/receive", [
            'received_by' => 'admin',
            'items'       => [
                ['inbound_item_id' => $itemUuid, 'qty' => 10],
            ],
        ])->assertOk();

        // 6. Close receiving
        $this->postJson("/api/v1/inbounds/{$inboundId}/close-receiving", [
            'closed_by' => 'admin',
        ])->assertOk();

        // 7. Worker scans item UUID + bin UUID → putaway
        $this->actingAs($this->worker, 'sanctum');

        $putawayResponse = $this->postJson('/api/v1/inbounds/scan-putaway', [
            'inbound_item_id' => $itemUuid,
            'bin_id'          => $this->storageBin->id,
            'qty'             => 10,
        ]);
        $putawayResponse->assertOk();

        // 8. Verify inventory updated
        $inventory = \DB::table('inventories')
            ->where('item_id', $this->variant->id)
            ->where('bin_id', $this->storageBin->id)
            ->first();

        $this->assertNotNull($inventory);
        $this->assertEquals(10, $inventory->on_hand);

        // 9. Verify inbound completed
        $inbound = Inbound::find($inboundId);
        $this->assertEquals(Inbound::STATUS_COMPLETED, $inbound->status);

        // 10. Verify assignment auto-completed
        $assignment = InboundAssignment::find($assignmentId);
        $this->assertEquals(InboundAssignment::STATUS_COMPLETED, $assignment->status);
        $this->assertNotNull($assignment->completed_at);
    }

    // ═══════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════

    private function createDraftInbound(): Inbound
    {
        $response = $this->postJson('/api/v1/inbounds', [
            'location_id'   => $this->warehouse->id,
            'type'          => 'PURCHASE_ORDER',
            'expected_date' => now()->toDateString(),
            'created_by'    => 'admin',
            'items'         => [
                ['item_id' => $this->variant->id, 'expected_qty' => 20],
            ],
        ]);

        return Inbound::find($response->json('data.id'));
    }

    private function createReceivedInbound(): Inbound
    {
        $inbound = $this->createDraftInbound();

        $inboundItemId = \DB::table('inbound_items')
            ->where('inbound_id', $inbound->id)->first()->id;

        $this->postJson("/api/v1/inbounds/{$inbound->id}/receive", [
            'received_by' => 'staff',
            'items'       => [
                ['inbound_item_id' => $inboundItemId, 'qty' => 20],
            ],
        ]);

        $this->postJson("/api/v1/inbounds/{$inbound->id}/close-receiving", [
            'closed_by' => 'admin',
        ]);

        return $inbound->fresh();
    }

    private function createFullyCompletedInbound(): Inbound
    {
        $inbound = $this->createReceivedInbound();

        $this->postJson("/api/v1/inbounds/{$inbound->id}/auto-putaway", [
            'created_by' => 'auto_system',
        ]);

        return $inbound->fresh();
    }
}
