<?php

namespace Modules\Sales\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Modules\Sales\Enums\OrderActivityAction;
use Modules\Sales\Models\BulkShippingLabelBatch;
use Modules\Sales\Models\BulkShippingLabelItem;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderStatusHistory;
use Modules\Sales\Services\SalesOrderService;
use Tests\TestCase;

class PrintShippingLabelAuditTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createPrivilegedUser();
        Storage::fake('documents');
    }

    public function test_single_order_label_printed_logs_audit_history(): void
    {
        $order = SalesOrder::factory()->create([
            'status' => 'picked',
            'channel_order_no' => 'SO-PRINT-TEST-001',
            'salesorder_no' => 'SO-PRINT-TEST-001',
            'tracking_number' => 'AWB-TEST-999888',
            'shipping_label_status' => 'ready',
            'shipping_label_doc_type' => 'THERMAL_AIR_WAYBILL',
        ]);

        $service = app(SalesOrderService::class);
        $service->logLabelPrinted($order, $this->user, 'THERMAL_AIR_WAYBILL');

        $histories = SalesOrderStatusHistory::where('salesorder_id', $order->id)
            ->where('action', OrderActivityAction::LABEL_PRINTED)
            ->get();

        $this->assertCount(1, $histories);
        $hist = $histories->first();
        $this->assertSame($this->user->id, $hist->actor_id);
        $this->assertSame($this->user->name, $hist->actor_name);
        $this->assertSame($this->user->email, $hist->actor_email);
        $this->assertSame('THERMAL_AIR_WAYBILL', $hist->metadata['document_type']);
        $this->assertSame('AWB-TEST-999888', $hist->metadata['tracking_number']);
    }

    public function test_rapid_consecutive_print_calls_are_deduplicated(): void
    {
        $order = SalesOrder::factory()->create([
            'status' => 'picked',
            'channel_order_no' => 'SO-PRINT-TEST-002',
            'salesorder_no' => 'SO-PRINT-TEST-002',
        ]);

        $service = app(SalesOrderService::class);
        // Call twice immediately
        $service->logLabelPrinted($order, $this->user);
        $service->logLabelPrinted($order, $this->user);

        $count = SalesOrderStatusHistory::where('salesorder_id', $order->id)
            ->where('action', OrderActivityAction::LABEL_PRINTED)
            ->count();

        $this->assertSame(1, $count, 'Panggilan cetak berturut-turut harus di-deduplikasi agar tidak spam');
    }

    public function test_bulk_shipping_label_pdf_download_logs_audit_for_all_batch_orders(): void
    {
        $order1 = SalesOrder::factory()->create([
            'status' => 'picked',
            'channel_order_no' => 'SO-BULK-001',
            'salesorder_no' => 'SO-BULK-001',
        ]);
        $order2 = SalesOrder::factory()->create([
            'status' => 'picked',
            'channel_order_no' => 'SO-BULK-002',
            'salesorder_no' => 'SO-BULK-002',
        ]);

        $batch = BulkShippingLabelBatch::create([
            'user_id' => $this->user->id,
            'status' => BulkShippingLabelBatch::STATUS_READY,
            'merged_pdf_path' => 'bulk-labels/test-batch.pdf',
            'total_count' => 2,
            'done_count' => 2,
            'failed_count' => 0,
            'skipped_count' => 0,
        ]);

        BulkShippingLabelItem::create([
            'batch_id' => $batch->id,
            'order_id' => $order1->id,
            'channel'  => 'shopee',
            'status'   => BulkShippingLabelItem::STATUS_DONE,
        ]);

        BulkShippingLabelItem::create([
            'batch_id' => $batch->id,
            'order_id' => $order2->id,
            'channel'  => 'shopee',
            'status'   => BulkShippingLabelItem::STATUS_DONE,
        ]);

        Storage::disk('documents')->put('bulk-labels/test-batch.pdf', '%PDF-1.4 dummy content');

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/sales/shipping-labels/bulk/{$batch->id}/pdf");

        $response->assertStatus(200);

        // Assert both orders have LABEL_PRINTED history
        $this->assertDatabaseHas('sales_order_status_histories', [
            'salesorder_id' => $order1->id,
            'action' => 'LABEL_PRINTED',
            'actor_id' => $this->user->id,
        ]);
        $this->assertDatabaseHas('sales_order_status_histories', [
            'salesorder_id' => $order2->id,
            'action' => 'LABEL_PRINTED',
            'actor_id' => $this->user->id,
        ]);
    }
}
