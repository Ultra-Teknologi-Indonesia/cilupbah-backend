<?php

namespace Modules\Purchase\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inbound\Models\Inbound;
use Modules\Inbound\Models\InboundItem;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Purchase\Repositories\PurchaseOrderRepository;
use Modules\Supplier\Models\Contact;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class PurchaseOrderQcAggregationTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseOrderRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = app(PurchaseOrderRepository::class);
    }

    private function makePo(int $qty = 100): array
    {
        $location = Location::create([
            'location_code' => 'WH-QC',
            'location_name' => 'Gudang QC',
            'location_type' => 'warehouse',
            'is_warehouse'  => true,
            'is_active'     => true,
        ]);

        $contact = Contact::create([
            'code' => 'SUP-QC',
            'name' => 'Pemasok QC',
        ]);

        $categoryId = \DB::table('categories')->insertGetId([
            'name'       => 'Kategori QC',
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $product = Product::create([
            'category_id' => $categoryId,
            'name'        => 'Produk QC',
            'sku'         => 'QC-PROD',
            'is_active'   => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku'        => 'QC-PROD-1',
            'sell_price' => 10000,
            'is_active'  => true,
        ]);

        $po = PurchaseOrder::create([
            'po_number'   => 'PO-QC-' . uniqid(),
            'contact_id'  => $contact->id,
            'location_id' => $location->id,
            'status'      => PurchaseOrder::STATUS_OPEN,
            'order_date'  => now()->toDateString(),
            'sub_total'   => $qty * 10000,
            'total_disc'  => 0,
            'total_tax'   => 0,
            'total_amount' => $qty * 10000,
            'created_by'  => 'system',
        ]);

        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id'           => $variant->id,
            'qty'               => $qty,
            'received_qty'      => 0,
            'unit_price'        => 10000,
            'amount'            => $qty * 10000,
        ]);

        return [$po, $item];
    }

    private function receive(PurchaseOrder $po, PurchaseOrderItem $item, int $accepted, int $rejected, ?string $note = null): void
    {
        $inbound = Inbound::create([
            'location_id'        => $po->location_id,
            'transaction_number' => 'INB-QC-' . uniqid(),
            'reference_number'   => $po->po_number,
            'type'               => Inbound::TYPE_PURCHASE_ORDER,
            'source_type'        => 'purchase_order',
            'source_id'          => $po->id,
            'status'             => Inbound::STATUS_RECEIVED,
            'expected_date'      => now()->toDateString(),
            'created_by'         => 'system',
        ]);

        InboundItem::create([
            'inbound_id'     => $inbound->id,
            'item_id'        => $item->item_id,
            'expected_qty'   => $accepted + $rejected,
            'received_qty'   => $accepted,
            'rejected_qty'   => $rejected,
            'rejection_note' => $note,
        ]);

        $item->increment('received_qty', $accepted + $rejected);
    }

    public function test_findById_exposes_qc_summary_totals(): void
    {
        [$po, $item] = $this->makePo(100);
        $this->receive($po, $item, 70, 30, 'kemasan rusak');

        $result = $this->repository->findById($po->id);

        $this->assertSame(70, $result->qc_summary['total_accepted']);
        $this->assertSame(30, $result->qc_summary['total_rejected']);
    }

    public function test_findById_qc_summary_is_zero_when_nothing_received_yet(): void
    {
        [$po, ] = $this->makePo(100);

        $result = $this->repository->findById($po->id);

        $this->assertSame(0, $result->qc_summary['total_accepted']);
        $this->assertSame(0, $result->qc_summary['total_rejected']);
    }

    public function test_paginated_items_expose_per_item_rejected_and_accepted_qty(): void
    {
        [$po, $item] = $this->makePo(100);
        $this->receive($po, $item, 70, 30, 'kemasan rusak');

        $result = $this->repository->getPaginatedItems($po->id, 20);
        $row = $result->getCollection()->firstWhere('id', $item->id);

        $this->assertSame(30, $row->rejected_qty);
        $this->assertSame(70, $row->accepted_qty);
        $this->assertSame(['kemasan rusak'], $row->rejection_notes->all());
    }

    public function test_paginated_items_aggregate_across_multiple_receiving_batches(): void
    {
        [$po, $item] = $this->makePo(100);
        $this->receive($po, $item, 40, 10, 'sebagian rusak');
        $this->receive($po, $item, 30, 20, 'kadaluarsa');

        $result = $this->repository->getPaginatedItems($po->id, 20);
        $row = $result->getCollection()->firstWhere('id', $item->id);

        $this->assertSame(30, $row->rejected_qty);
        $this->assertSame(70, $row->accepted_qty);
        $this->assertEqualsCanonicalizing(
            ['sebagian rusak', 'kadaluarsa'],
            $row->rejection_notes->all(),
        );
    }
}
