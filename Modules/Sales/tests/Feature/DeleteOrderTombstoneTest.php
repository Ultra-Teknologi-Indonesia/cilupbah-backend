<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Exceptions\DuplicateOrderException;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Repositories\SalesOrderRepository;
use Modules\Sales\Services\SalesOrderService;
use Tests\TestCase;

class DeleteOrderTombstoneTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderRepository $repository;

    protected SalesOrderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = app(SalesOrderRepository::class);
        $this->service = app(SalesOrderService::class);
    }

    protected function orderData(string $orderNo): array
    {
        return [
            'salesorder_no' => $orderNo,
            'channel_shop_id' => 'SHOP-1',
            'customer_name' => 'Buyer Test',
            'transaction_date' => now(),
            'sub_total' => 30000,
            'total_disc' => 0,
            'total_tax' => 0,
            'shipping_cost' => 0,
            'insurance_cost' => 0,
            'grand_total' => 30000,
            'shipping_full_name' => null,
            'shipping_phone' => null,
            'shipping_address' => null,
            'shipping_city' => null,
            'shipping_province' => null,
            'shipping_post_code' => null,
            'shipping_country' => null,
            'channel_status' => 'UNPAID',
            'status' => 'pending',
            'is_paid' => false,
            'payment_method' => null,
            'source' => 'lazada',
        ];
    }

    protected function createOrder(string $orderNo = 'LZ-9001'): SalesOrder
    {
        return $this->repository->upsertOrderBySalesOrderNo($orderNo, $this->orderData($orderNo));
    }

    public function test_delete_order_soft_deletes_with_metadata(): void
    {
        $order = $this->createOrder();

        $this->service->deleteOrder($order, 'gudang@cilupbah.com', 'Pesanan tidak sesuai');

        $row = DB::table('sales_orders')->where('salesorder_no', 'LZ-9001')->first();

        $this->assertNotNull($row, 'Baris harus tetap ada (soft-delete, bukan hard-delete).');
        $this->assertNotNull($row->deleted_at);
        $this->assertSame('gudang@cilupbah.com', $row->deleted_by);
        $this->assertSame('Pesanan tidak sesuai', $row->delete_reason);

        $this->assertNull(SalesOrder::where('salesorder_no', 'LZ-9001')->first(), 'Order trashed tidak boleh muncul di query normal.');
    }

    public function test_upsert_from_channel_is_blocked_for_trashed_order(): void
    {
        $order = $this->createOrder();
        $this->service->deleteOrder($order, 'gudang@cilupbah.com', 'Pesanan tidak sesuai');

        $result = $this->repository->upsertOrderBySalesOrderNo('LZ-9001', $this->orderData('LZ-9001'));

        $this->assertNull($result, 'Upsert untuk order trashed harus di-skip (tombstone).');

        $row = DB::table('sales_orders')->where('salesorder_no', 'LZ-9001')->first();
        $this->assertNotNull($row->deleted_at, 'Baris trashed tidak boleh dihidupkan kembali oleh upsert.');
        $this->assertSame(1, DB::table('sales_orders')->where('salesorder_no', 'LZ-9001')->count());
    }

    public function test_create_order_is_blocked_for_trashed_order(): void
    {
        $order = $this->createOrder();
        $this->service->deleteOrder($order, 'gudang@cilupbah.com', 'Pesanan tidak sesuai');

        $this->expectException(DuplicateOrderException::class);

        $this->service->createOrder($this->orderData('LZ-9001'));
    }

    public function test_shipped_order_cannot_be_deleted(): void
    {
        $order = $this->createOrder();
        $order->update(['status' => 'shipped']);

        $this->expectException(\Exception::class);

        $this->service->deleteOrder($order->fresh(), 'gudang@cilupbah.com', null);
    }
}
