<?php

namespace Modules\Sales\Tests\Unit;

use Tests\TestCase;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Enums\WmsStatus;

class SalesOrderStatusLabelTest extends TestCase
{
    public function test_pending_order_has_correct_status_label()
    {
        $order = new SalesOrder(['status' => 'pending', 'is_paid' => false]);
        $this->assertEquals('Menunggu Pembayaran', $order->status_label);
        $this->assertEquals(WmsStatus::CREATED->value, $order->resolved_wms_status);
    }

    public function test_reserved_order_without_picklist_has_correct_status_label()
    {
        $order = new SalesOrder(['status' => 'reserved']);
        $this->assertEquals('Pengambilan - Belum Dimulai', $order->status_label);
        $this->assertEquals(WmsStatus::PROCESS->value, $order->resolved_wms_status);
    }

    public function test_reserved_order_with_failed_pick_has_correct_status_label()
    {
        $order = new SalesOrder(['status' => 'reserved', 'pick_failed_at' => now()]);
        $this->assertEquals('Gagal Pengambilan', $order->status_label);
        $this->assertEquals(WmsStatus::FAILED->value, $order->resolved_wms_status);
    }

    public function test_picked_order_has_correct_status_label()
    {
        $order = new SalesOrder(['status' => 'picked']);
        $this->assertEquals('Pengambilan - Selesai', $order->status_label);
        $this->assertEquals(WmsStatus::FINISH_PICK->value, $order->resolved_wms_status);
    }

    public function test_packed_order_has_correct_status_label()
    {
        $order = new SalesOrder(['status' => 'packed']);
        $this->assertEquals('Pengepakan - Selesai', $order->status_label);
        $this->assertEquals(WmsStatus::FINISH_PACK->value, $order->resolved_wms_status);
    }

    public function test_shipped_order_has_correct_status_label()
    {
        $order = new SalesOrder(['status' => 'shipped', 'received_date' => null]);
        $this->assertEquals('Pengiriman - Sedang Dikirim', $order->status_label);
        $this->assertEquals(WmsStatus::SHIPPED->value, $order->resolved_wms_status);
    }

    public function test_completed_order_has_correct_status_label()
    {
        $order = new SalesOrder(['status' => 'shipped', 'received_date' => now()]);
        $this->assertEquals('Selesai', $order->status_label);
        $this->assertEquals(WmsStatus::COMPLETED->value, $order->resolved_wms_status);
    }

    public function test_cancelled_order_has_correct_status_label()
    {
        $order = new SalesOrder(['status' => 'cancelled']);
        $this->assertEquals('Dibatalkan', $order->status_label);
        $this->assertEquals(WmsStatus::CANCELLED->value, $order->resolved_wms_status);
    }
}
