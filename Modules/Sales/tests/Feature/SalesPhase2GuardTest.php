<?php

namespace Modules\Sales\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesInvoice;
use Modules\Sales\Models\SalesReturn;
use Tests\TestCase;

class SalesPhase2GuardTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected string $variantId;
    protected string $locationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Kategori P2',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = Str::uuid()->toString();
        DB::table('products')->insert([
            'id' => $productId,
            'category_id' => $categoryId,
            'name' => 'Produk P2',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->variantId = Str::uuid()->toString();
        DB::table('product_variants')->insert([
            'id' => $this->variantId,
            'product_id' => $productId,
            'sku' => 'SKU-P2',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->locationId = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $this->locationId,
            'location_code' => 'LOC-P2',
            'location_name' => 'Gudang P2',
            'location_type' => 'WAREHOUSE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->variantId,
            'location_id' => $this->locationId,
            'bin_id' => null,
            'on_hand' => 100,
            'on_order' => 0,
            'available' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function orderPayload(string $orderNo): array
    {
        return [
            'salesorder_no' => $orderNo,
            'customer_name' => 'Buyer P2',
            'items' => [[
                'sku' => 'SKU-P2',
                'qty_in_base' => 1,
                'price' => 5000,
            ]],
        ];
    }

    public function test_duplicate_order_returns_409(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/sales', $this->orderPayload('DUP-1'))
            ->assertStatus(201);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/sales', $this->orderPayload('DUP-1'))
            ->assertStatus(409);
    }

    public function test_cancel_frees_idempotency_key_so_number_can_be_reused(): void
    {
        $create = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/sales', $this->orderPayload('DUP-2'))
            ->assertStatus(201);

        $orderId = $create->json('data.id');

        $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/v1/sales/{$orderId}", ['status' => 'cancelled'])
            ->assertStatus(200);

        DB::table('sales_orders')->where('id', $orderId)->delete();

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/sales', $this->orderPayload('DUP-2'))
            ->assertStatus(201);
    }

    protected function makeReturn(string $status): SalesReturn
    {
        return SalesReturn::create([
            'return_number' => 'RET-' . Str::upper(Str::random(6)),
            'location_id'   => $this->locationId,
            'source'        => SalesReturn::SOURCE_MANUAL,
            'status'        => $status,
            'created_by'    => 'tester',
        ]);
    }

    public function test_complete_return_from_pending_returns_409(): void
    {
        $return = $this->makeReturn(SalesReturn::STATUS_PENDING);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/sales/returns/{$return->id}/complete", ['processed_by' => 'tester'])
            ->assertStatus(409);

        $this->assertDatabaseHas('sales_returns', [
            'id'     => $return->id,
            'status' => SalesReturn::STATUS_PENDING,
        ]);
    }

    public function test_complete_return_from_accepted_succeeds(): void
    {
        $return = $this->makeReturn(SalesReturn::STATUS_ACCEPTED);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/sales/returns/{$return->id}/complete", ['processed_by' => 'tester'])
            ->assertStatus(200);

        $this->assertDatabaseHas('sales_returns', [
            'id'     => $return->id,
            'status' => SalesReturn::STATUS_COMPLETED,
        ]);
    }

    protected function makeInvoice(float $total): SalesInvoice
    {
        return SalesInvoice::create([
            'invoice_number' => 'INV-' . Str::upper(Str::random(6)),
            'location_id'    => $this->locationId,
            'status'         => SalesInvoice::STATUS_OPEN,
            'invoice_date'   => now()->toDateString(),
            'total_amount'   => $total,
            'paid_amount'    => 0,
            'created_by'     => 'tester',
        ]);
    }

    protected function paymentPayload(string $invoiceId, float $amount): array
    {
        return [
            'sales_invoice_id' => $invoiceId,
            'amount'           => $amount,
            'payment_date'     => now()->toDateString(),
            'payment_method'   => 'CASH',
            'created_by'       => 'tester',
        ];
    }

    public function test_overpayment_returns_422(): void
    {
        $invoice = $this->makeInvoice(100000);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/sales/payments', $this->paymentPayload($invoice->id, 150000))
            ->assertStatus(422);

        $this->assertDatabaseHas('sales_invoices', [
            'id'          => $invoice->id,
            'paid_amount' => 0,
            'status'      => SalesInvoice::STATUS_OPEN,
        ]);
    }

    public function test_partial_then_full_payment_keeps_status_consistent(): void
    {
        $invoice = $this->makeInvoice(100000);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/sales/payments', $this->paymentPayload($invoice->id, 40000))
            ->assertStatus(201);

        $this->assertSame(SalesInvoice::STATUS_OPEN, $invoice->fresh()->status);

        $full = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/sales/payments', $this->paymentPayload($invoice->id, 60000))
            ->assertStatus(201);

        $this->assertSame(SalesInvoice::STATUS_PAID, $invoice->fresh()->status);

        $paymentId = $full->json('data.id');
        $this->actingAs($this->user, 'sanctum')
            ->deleteJson('/api/v1/sales/payments', ['id' => $paymentId])
            ->assertStatus(200);

        $this->assertSame(SalesInvoice::STATUS_OPEN, $invoice->fresh()->status);
        $this->assertEquals(40000, $invoice->fresh()->paid_amount);
    }
}
