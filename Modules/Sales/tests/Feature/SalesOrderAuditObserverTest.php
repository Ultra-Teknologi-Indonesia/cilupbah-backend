<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderStatusHistory;
use Tests\TestCase;

class SalesOrderAuditObserverTest extends TestCase
{
    use RefreshDatabase;

    private string $locationId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->locationId = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $this->locationId,
            'location_code' => 'LOC-AUDIT',
            'location_name' => 'Gudang Audit',
            'location_type' => 'WAREHOUSE',
            'is_warehouse' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createOrder(string $status = 'reserved'): SalesOrder
    {
        return SalesOrder::create([
            'id' => Str::uuid()->toString(),
            'salesorder_no' => 'SO-' . strtoupper(Str::random(6)),
            'customer_name' => 'Buyer Audit',
            'source' => 'manual',
            'location_id' => $this->locationId,
            'status' => $status,
            'is_paid' => true,
        ]);
    }

    public function test_reverting_from_packed_to_picked_logs_batal_packing(): void
    {
        $order = $this->createOrder('packed');

        $order->update(['status' => 'picked']);

        $history = SalesOrderStatusHistory::where('salesorder_id', $order->id)
            ->where('action', 'FIELD_CHANGED')
            ->latest('id')
            ->first();

        $this->assertNotNull($history);
        $this->assertSame('FIELD_CHANGED', $history->action->value);
        $this->assertSame('Batal Packing (Kembali ke Picking)', $history->metadata['note'] ?? null);
        $this->assertSame('packed', $history->metadata['prev_values']['status'] ?? null);
        $this->assertSame('picked', $history->metadata['new_values']['status'] ?? null);
    }

    public function test_failing_pick_logs_pick_failed_with_reason_and_note(): void
    {
        $order = $this->createOrder('picked');

        $order->update([
            'status' => 'reserved',
            'pick_failed_at' => now(),
            'pick_failed_by' => 'tester@cilupbah.test',
            'pick_fail_reason' => 'Stok habis di rak',
        ]);

        $history = SalesOrderStatusHistory::where('salesorder_id', $order->id)
            ->where('action', 'PICK_FAILED')
            ->latest('id')
            ->first();

        $this->assertNotNull($history);
        $this->assertSame('PICK_FAILED', $history->action->value);
        $this->assertSame('Gagal Picking: Stok habis di rak', $history->metadata['note'] ?? null);
        $this->assertSame('picked', $history->metadata['prev_values']['status'] ?? null);
        $this->assertSame('reserved', $history->metadata['new_values']['status'] ?? null);
        $this->assertSame('Stok habis di rak', $history->metadata['new_values']['pick_fail_reason'] ?? null);
    }

    public function test_reverting_from_picked_to_reserved_without_failure_logs_batal_picking(): void
    {
        $order = $this->createOrder('picked');

        $order->update(['status' => 'reserved']);

        $history = SalesOrderStatusHistory::where('salesorder_id', $order->id)
            ->where('action', 'FIELD_CHANGED')
            ->latest('id')
            ->first();

        $this->assertNotNull($history);
        $this->assertSame('FIELD_CHANGED', $history->action->value);
        $this->assertSame('Batal Picking (Kembali ke Siap Proses)', $history->metadata['note'] ?? null);
        $this->assertSame('picked', $history->metadata['prev_values']['status'] ?? null);
        $this->assertSame('reserved', $history->metadata['new_values']['status'] ?? null);
    }
}
