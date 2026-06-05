<?php

namespace Tests\Feature\Order;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Modules\Inventory\Models\Inventory;
use Modules\Order\Models\Order;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class OrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;
    private Product $product;
    private ProductVariant $variant;
    private Inventory $inventory;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedWarehouseAndStock();
    }

    private function seedWarehouseAndStock(int $available = 100): void
    {
        $this->location = Location::create([
            'location_code' => 'WH-01',
            'location_name' => 'Gudang Utama',
            'location_type' => 'warehouse',
            'is_warehouse'  => true,
            'is_active'     => true,
        ]);

        $category = \DB::table('categories')->insertGetId([
            'name'       => 'Electronics',
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->product = Product::create([
            'category_id' => $category,
            'name'        => 'iPhone 16 Pro',
            'sku'         => 'IP16PRO',
            'is_active'   => true,
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku'        => 'IP16PRO-256-BLK',
            'sell_price' => 19999000,
            'is_active'  => true,
        ]);

        $this->inventory = Inventory::create([
            'item_id'     => $this->product->id,
            'location_id' => $this->location->id,
            'bin_id'      => null,
            'batch_no'    => '',
            'serial_no'   => '',
            'on_hand'     => $available,
            'on_order'    => 0,
            'reserved'    => 0,
            'available'   => $available,
        ]);
    }

    private function orderPayload(array $overrides = []): array
    {
        return array_merge([
            'salesorder_no'      => 'SO-' . uniqid(),
            'customer_name'      => 'Toko Maju Jaya',
            'transaction_date'   => now()->toDateTimeString(),
            'sub_total'          => 19999000,
            'total_disc'         => 0,
            'total_tax'          => 0,
            'shipping_cost'      => 25000,
            'insurance_cost'     => 0,
            'grand_total'        => 20024000,
            'shipping_full_name' => 'Budi Santoso',
            'shipping_phone'     => '08123456789',
            'shipping_address'   => 'Jl. Sudirman No. 1',
            'shipping_city'      => 'Jakarta',
            'shipping_province'  => 'DKI Jakarta',
            'shipping_post_code' => '12190',
            'shipping_country'   => 'Indonesia',
            'payment_method'     => 'bank_transfer',
            'source'             => 'tiktok',
            'items'              => [
                [
                    'sku'         => $this->variant->sku,
                    'description' => 'iPhone 16 Pro 256GB Black',
                    'qty_in_base' => 2,
                    'price'       => 19999000,
                    'amount'      => 19999000,
                ],
            ],
        ], $overrides);
    }

    // ─── CREATE ──────────────────────────────────────────────

    public function test_create_order_returns_201_with_reserved_status(): void
    {
        $payload = $this->orderPayload();

        $response = $this->postJson('/api/v1/orders', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'reserved')
            ->assertJsonPath('data.customer_name', 'Toko Maju Jaya')
            ->assertJsonCount(1, 'data.items');

        $this->assertDatabaseHas('orders', [
            'salesorder_no' => $payload['salesorder_no'],
            'status'        => 'reserved',
        ]);
    }

    public function test_create_order_reserves_stock(): void
    {
        $payload = $this->orderPayload(['items' => [
            [
                'sku'         => $this->variant->sku,
                'description' => 'iPhone 16 Pro',
                'qty_in_base' => 3,
                'price'       => 19999000,
                'amount'      => 59997000,
            ],
        ]]);

        $this->postJson('/api/v1/orders', $payload)->assertStatus(201);

        $this->inventory->refresh();
        $this->assertEquals(97, $this->inventory->available);
        $this->assertEquals(3, $this->inventory->reserved);
    }

    public function test_create_order_records_inventory_movement(): void
    {
        $payload = $this->orderPayload();

        $this->postJson('/api/v1/orders', $payload)->assertStatus(201);

        $this->assertDatabaseHas('inventory_movements', [
            'item_id'            => $this->product->id,
            'source'             => 'ORDER_RESERVE',
            'transaction_number' => $payload['salesorder_no'],
        ]);
    }

    public function test_duplicate_order_returns_409(): void
    {
        $payload = $this->orderPayload(['salesorder_no' => 'SO-DUPE-001']);

        $this->postJson('/api/v1/orders', $payload)->assertStatus(201);
        $this->postJson('/api/v1/orders', $payload)->assertStatus(409);
    }

    public function test_insufficient_stock_returns_422(): void
    {
        $payload = $this->orderPayload(['items' => [
            [
                'sku'         => $this->variant->sku,
                'description' => 'iPhone 16 Pro',
                'qty_in_base' => 999,
                'price'       => 19999000,
                'amount'      => 19999000,
            ],
        ]]);

        $response = $this->postJson('/api/v1/orders', $payload);

        $response->assertStatus(422)
            ->assertJsonFragment(['status' => 'error']);
    }

    public function test_create_order_requires_items(): void
    {
        $payload = $this->orderPayload();
        unset($payload['items']);

        $this->postJson('/api/v1/orders', $payload)->assertStatus(422);
    }

    // ─── READ ────────────────────────────────────────────────

    public function test_list_orders_returns_paginated(): void
    {
        $this->postJson('/api/v1/orders', $this->orderPayload())->assertStatus(201);
        $this->postJson('/api/v1/orders', $this->orderPayload())->assertStatus(201);

        $response = $this->getJson('/api/v1/orders');

        $response->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonCount(2, 'data');
    }

    public function test_show_order_with_items(): void
    {
        $create = $this->postJson('/api/v1/orders', $this->orderPayload());
        $orderId = $create->json('data.id');

        $response = $this->getJson("/api/v1/orders/{$orderId}?include=items");

        $response->assertOk()
            ->assertJsonPath('data.id', $orderId)
            ->assertJsonPath('data.status', 'reserved');
    }

    public function test_show_nonexistent_order_returns_404(): void
    {
        $this->getJson('/api/v1/orders/99999')->assertStatus(404);
    }

    // ─── STATUS FLOW: HAPPY PATH ─────────────────────────────

    public function test_full_lifecycle_reserved_to_shipped(): void
    {
        $create = $this->postJson('/api/v1/orders', $this->orderPayload());
        $orderId = $create->json('data.id');

        // reserved → picked
        $this->putJson("/api/v1/orders/{$orderId}", ['status' => 'picked'])
            ->assertOk()
            ->assertJsonPath('data.status', 'picked');

        // picked → packed
        $this->putJson("/api/v1/orders/{$orderId}", ['status' => 'packed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'packed');

        // packed → shipped
        $this->putJson("/api/v1/orders/{$orderId}", ['status' => 'shipped'])
            ->assertOk()
            ->assertJsonPath('data.status', 'shipped');

        $this->inventory->refresh();
        $this->assertEquals(0, $this->inventory->reserved);
        $this->assertEquals(98, $this->inventory->on_hand);
    }

    public function test_pick_decrements_reserved(): void
    {
        $create = $this->postJson('/api/v1/orders', $this->orderPayload([
            'items' => [[
                'sku' => $this->variant->sku, 'description' => 'test',
                'qty_in_base' => 5, 'price' => 100, 'amount' => 500,
            ]],
        ]));
        $orderId = $create->json('data.id');

        $this->inventory->refresh();
        $this->assertEquals(5, $this->inventory->reserved);

        $this->putJson("/api/v1/orders/{$orderId}", ['status' => 'picked']);

        $this->inventory->refresh();
        $this->assertEquals(0, $this->inventory->reserved);
    }

    public function test_ship_decrements_on_hand(): void
    {
        $create = $this->postJson('/api/v1/orders', $this->orderPayload([
            'items' => [[
                'sku' => $this->variant->sku, 'description' => 'test',
                'qty_in_base' => 5, 'price' => 100, 'amount' => 500,
            ]],
        ]));
        $orderId = $create->json('data.id');

        $this->putJson("/api/v1/orders/{$orderId}", ['status' => 'picked']);
        $this->putJson("/api/v1/orders/{$orderId}", ['status' => 'packed']);
        $this->putJson("/api/v1/orders/{$orderId}", ['status' => 'shipped']);

        $this->inventory->refresh();
        $this->assertEquals(95, $this->inventory->on_hand);
    }

    // ─── STATUS FLOW: INVALID TRANSITIONS ────────────────────

    public function test_cannot_skip_reserved_to_packed(): void
    {
        $create = $this->postJson('/api/v1/orders', $this->orderPayload());
        $orderId = $create->json('data.id');

        $this->putJson("/api/v1/orders/{$orderId}", ['status' => 'packed'])
            ->assertStatus(422);
    }

    public function test_cannot_skip_reserved_to_shipped(): void
    {
        $create = $this->postJson('/api/v1/orders', $this->orderPayload());
        $orderId = $create->json('data.id');

        $this->putJson("/api/v1/orders/{$orderId}", ['status' => 'shipped'])
            ->assertStatus(422);
    }

    public function test_cannot_transition_from_shipped(): void
    {
        $create = $this->postJson('/api/v1/orders', $this->orderPayload());
        $orderId = $create->json('data.id');

        $this->putJson("/api/v1/orders/{$orderId}", ['status' => 'picked']);
        $this->putJson("/api/v1/orders/{$orderId}", ['status' => 'packed']);
        $this->putJson("/api/v1/orders/{$orderId}", ['status' => 'shipped']);

        $this->putJson("/api/v1/orders/{$orderId}", ['status' => 'cancelled'])
            ->assertStatus(422);
    }

    public function test_cannot_transition_from_cancelled(): void
    {
        $create = $this->postJson('/api/v1/orders', $this->orderPayload());
        $orderId = $create->json('data.id');

        $this->putJson("/api/v1/orders/{$orderId}", ['status' => 'cancelled']);

        $this->putJson("/api/v1/orders/{$orderId}", ['status' => 'picked'])
            ->assertStatus(422);
    }

    // ─── CANCEL FLOW ─────────────────────────────────────────

    public function test_cancel_reserved_order_releases_stock(): void
    {
        $create = $this->postJson('/api/v1/orders', $this->orderPayload([
            'items' => [[
                'sku' => $this->variant->sku, 'description' => 'test',
                'qty_in_base' => 10, 'price' => 100, 'amount' => 1000,
            ]],
        ]));
        $orderId = $create->json('data.id');

        $this->inventory->refresh();
        $this->assertEquals(90, $this->inventory->available);
        $this->assertEquals(10, $this->inventory->reserved);

        $this->putJson("/api/v1/orders/{$orderId}", ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->inventory->refresh();
        $this->assertEquals(100, $this->inventory->available);
        $this->assertEquals(0, $this->inventory->reserved);

        $this->assertDatabaseHas('orders', [
            'id'          => $orderId,
            'is_canceled' => true,
        ]);
    }

    public function test_cancel_picked_order_releases_stock(): void
    {
        $create = $this->postJson('/api/v1/orders', $this->orderPayload([
            'items' => [[
                'sku' => $this->variant->sku, 'description' => 'test',
                'qty_in_base' => 5, 'price' => 100, 'amount' => 500,
            ]],
        ]));
        $orderId = $create->json('data.id');

        $this->putJson("/api/v1/orders/{$orderId}", ['status' => 'picked']);

        $this->putJson("/api/v1/orders/{$orderId}", ['status' => 'cancelled'])
            ->assertOk();

        $this->inventory->refresh();
        $this->assertEquals(100, $this->inventory->available);
    }

    public function test_cancel_packed_order_releases_stock(): void
    {
        $create = $this->postJson('/api/v1/orders', $this->orderPayload([
            'items' => [[
                'sku' => $this->variant->sku, 'description' => 'test',
                'qty_in_base' => 5, 'price' => 100, 'amount' => 500,
            ]],
        ]));
        $orderId = $create->json('data.id');

        $this->putJson("/api/v1/orders/{$orderId}", ['status' => 'picked']);
        $this->putJson("/api/v1/orders/{$orderId}", ['status' => 'packed']);

        $this->putJson("/api/v1/orders/{$orderId}", ['status' => 'cancelled'])
            ->assertOk();

        $this->inventory->refresh();
        $this->assertEquals(100, $this->inventory->available);
    }

    // ─── DELETE ──────────────────────────────────────────────

    public function test_delete_pending_order(): void
    {
        $order = Order::create(array_merge($this->orderPayload(), ['status' => 'pending']));

        $this->deleteJson("/api/v1/orders/{$order->id}")->assertOk();

        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
    }

    public function test_delete_cancelled_order(): void
    {
        $order = Order::create(array_merge($this->orderPayload(), [
            'status'      => 'cancelled',
            'is_canceled' => true,
        ]));

        $this->deleteJson("/api/v1/orders/{$order->id}")->assertOk();

        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
    }

    public function test_delete_reserved_order_releases_stock_then_deletes(): void
    {
        $create = $this->postJson('/api/v1/orders', $this->orderPayload([
            'items' => [[
                'sku' => $this->variant->sku, 'description' => 'test',
                'qty_in_base' => 8, 'price' => 100, 'amount' => 800,
            ]],
        ]));
        $orderId = $create->json('data.id');

        $this->inventory->refresh();
        $this->assertEquals(92, $this->inventory->available);

        $this->deleteJson("/api/v1/orders/{$orderId}")->assertOk();

        $this->assertDatabaseMissing('orders', ['id' => $orderId]);

        $this->inventory->refresh();
        $this->assertEquals(100, $this->inventory->available);
    }

    public function test_cannot_delete_picked_order(): void
    {
        $create = $this->postJson('/api/v1/orders', $this->orderPayload());
        $orderId = $create->json('data.id');

        $this->putJson("/api/v1/orders/{$orderId}", ['status' => 'picked']);

        $this->deleteJson("/api/v1/orders/{$orderId}")->assertStatus(422);
    }

    public function test_cannot_delete_shipped_order(): void
    {
        $create = $this->postJson('/api/v1/orders', $this->orderPayload());
        $orderId = $create->json('data.id');

        $this->putJson("/api/v1/orders/{$orderId}", ['status' => 'picked']);
        $this->putJson("/api/v1/orders/{$orderId}", ['status' => 'packed']);
        $this->putJson("/api/v1/orders/{$orderId}", ['status' => 'shipped']);

        $this->deleteJson("/api/v1/orders/{$orderId}")->assertStatus(422);
    }

    // ─── UPDATE FIELDS ───────────────────────────────────────

    public function test_update_allowed_fields(): void
    {
        $create = $this->postJson('/api/v1/orders', $this->orderPayload());
        $orderId = $create->json('data.id');

        $this->putJson("/api/v1/orders/{$orderId}", [
            'customer_name'    => 'Toko Baru Sejahtera',
            'shipping_address' => 'Jl. Gatot Subroto No. 99',
            'seller_note'      => 'Handle with care',
        ])->assertOk();

        $this->assertDatabaseHas('orders', [
            'id'               => $orderId,
            'customer_name'    => 'Toko Baru Sejahtera',
            'shipping_address' => 'Jl. Gatot Subroto No. 99',
            'seller_note'      => 'Handle with care',
        ]);
    }

    public function test_update_status_rejects_invalid_value(): void
    {
        $create = $this->postJson('/api/v1/orders', $this->orderPayload());
        $orderId = $create->json('data.id');

        $this->putJson("/api/v1/orders/{$orderId}", ['status' => 'bogus'])
            ->assertStatus(422);
    }

    // ─── IDEMPOTENCY ─────────────────────────────────────────

    public function test_idempotency_key_cleared_on_delete(): void
    {
        $payload = $this->orderPayload(['salesorder_no' => 'SO-IDEM-001']);

        $create = $this->postJson('/api/v1/orders', $payload)->assertStatus(201);
        $orderId = $create->json('data.id');

        // release stock first so delete is allowed
        $this->putJson("/api/v1/orders/{$orderId}", ['status' => 'cancelled']);
        $this->deleteJson("/api/v1/orders/{$orderId}")->assertOk();

        // re-seed stock (cancelled order released it, but we need fresh inventory)
        $this->inventory->refresh();

        // should be able to create again with same salesorder_no
        $this->postJson('/api/v1/orders', $payload)->assertStatus(201);
    }

    // ─── MULTI-ITEM ORDERS ──────────────────────────────────

    public function test_multi_item_order_reserves_stock_per_line(): void
    {
        $category = \DB::table('categories')->first();

        $product2 = Product::create([
            'category_id' => $category->id,
            'name'        => 'Samsung Galaxy S25',
            'sku'         => 'SGS25',
            'is_active'   => true,
        ]);

        $variant2 = ProductVariant::create([
            'product_id' => $product2->id,
            'sku'        => 'SGS25-256-BLK',
            'sell_price' => 14999000,
            'is_active'  => true,
        ]);

        $inventory2 = Inventory::create([
            'item_id'     => $product2->id,
            'location_id' => $this->location->id,
            'bin_id'      => null,
            'batch_no'    => '',
            'serial_no'   => '',
            'on_hand'     => 50,
            'on_order'    => 0,
            'reserved'    => 0,
            'available'   => 50,
        ]);

        $payload = $this->orderPayload([
            'items' => [
                [
                    'sku' => $this->variant->sku, 'description' => 'iPhone 16 Pro',
                    'qty_in_base' => 3, 'price' => 19999000, 'amount' => 59997000,
                ],
                [
                    'sku' => $variant2->sku, 'description' => 'Samsung Galaxy S25',
                    'qty_in_base' => 2, 'price' => 14999000, 'amount' => 29998000,
                ],
            ],
        ]);

        $this->postJson('/api/v1/orders', $payload)->assertStatus(201);

        $this->inventory->refresh();
        $this->assertEquals(97, $this->inventory->available);
        $this->assertEquals(3, $this->inventory->reserved);

        $inventory2->refresh();
        $this->assertEquals(48, $inventory2->available);
        $this->assertEquals(2, $inventory2->reserved);
    }

    // ─── CONCURRENT STOCK SAFETY ─────────────────────────────

    public function test_stock_cannot_go_negative_across_orders(): void
    {
        $this->inventory->update([
            'on_hand'   => 5,
            'on_order'  => 0,
            'reserved'  => 0,
            'available' => 5,
        ]);

        $payload1 = $this->orderPayload([
            'salesorder_no' => 'SO-RACE-1',
            'items'         => [[
                'sku' => $this->variant->sku, 'description' => 'test',
                'qty_in_base' => 4, 'price' => 100, 'amount' => 400,
            ]],
        ]);

        $payload2 = $this->orderPayload([
            'salesorder_no' => 'SO-RACE-2',
            'items'         => [[
                'sku' => $this->variant->sku, 'description' => 'test',
                'qty_in_base' => 4, 'price' => 100, 'amount' => 400,
            ]],
        ]);

        $r1 = $this->postJson('/api/v1/orders', $payload1);
        $r1->assertStatus(201);

        $r2 = $this->postJson('/api/v1/orders', $payload2);
        $r2->assertStatus(422);

        $this->inventory->refresh();
        $this->assertEquals(1, $this->inventory->available);
        $this->assertEquals(4, $this->inventory->reserved);
    }

    // ─── MOVEMENT AUDIT TRAIL ────────────────────────────────

    public function test_full_lifecycle_creates_correct_movements(): void
    {
        $payload = $this->orderPayload();
        $so = $payload['salesorder_no'];

        $create = $this->postJson('/api/v1/orders', $payload);
        $create->assertStatus(201);
        $orderId = $create->json('data.id');

        $this->putJson("/api/v1/orders/{$orderId}", ['status' => 'picked'])->assertOk();
        $this->putJson("/api/v1/orders/{$orderId}", ['status' => 'packed'])->assertOk();
        $this->putJson("/api/v1/orders/{$orderId}", ['status' => 'shipped'])->assertOk();

        $this->assertDatabaseHas('inventory_movements', [
            'transaction_number' => $so, 'source' => 'ORDER_RESERVE',
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'transaction_number' => $so, 'source' => 'ORDER_PICK',
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'transaction_number' => $so, 'source' => 'ORDER_SHIP',
        ]);
    }

    public function test_cancel_creates_cancel_movement(): void
    {
        $payload = $this->orderPayload();
        $so = $payload['salesorder_no'];

        $create = $this->postJson('/api/v1/orders', $payload);
        $create->assertStatus(201);
        $orderId = $create->json('data.id');

        $this->putJson("/api/v1/orders/{$orderId}", ['status' => 'cancelled'])->assertOk();

        $this->assertDatabaseHas('inventory_movements', [
            'transaction_number' => $so, 'source' => 'ORDER_CANCEL',
        ]);
    }
}
