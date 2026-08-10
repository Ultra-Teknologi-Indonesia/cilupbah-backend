<?php

namespace Modules\Sales\Tests\Unit;

use Modules\Sales\Http\Resources\SalesInvoiceResource;
use Modules\Sales\Models\SalesInvoice;
use Modules\Sales\Models\SalesInvoiceItem;
use Tests\TestCase;

class SalesInvoiceResourceParityTest extends TestCase
{
    private function makeInvoice(): SalesInvoice
    {
        $invoice = new SalesInvoice([
            'invoice_number' => 'INV-PARITY-1',
            'order_id'       => '0199aaaa-0000-7000-8000-000000000001',
            'customer_name'  => 'Budi',
            'location_id'    => '0199bbbb-0000-7000-8000-000000000002',
            'status'         => 'OPEN',
            'invoice_date'   => '2026-06-10',
            'due_date'       => '2026-07-10',
            'total_amount'   => 150000,
            'paid_amount'    => 50000,
            'notes'          => 'catatan uji',
            'created_by'     => 'tester',
        ]);
        $invoice->id = '0199cccc-0000-7000-8000-000000000003';
        $invoice->created_at = now();
        $invoice->updated_at = now();

        return $invoice;
    }

    public function test_resource_matches_model_without_relations(): void
    {
        $invoice = $this->makeInvoice();

        $expected = json_decode(json_encode($invoice->toArray()), true);
        $actual = json_decode(json_encode((new SalesInvoiceResource($invoice))->resolve()), true);

        $this->assertEquals($expected, $actual);
    }

    public function test_resource_matches_model_with_loaded_items(): void
    {
        $invoice = $this->makeInvoice();

        $item = new SalesInvoiceItem([
            'sales_invoice_id' => $invoice->id,
            'item_id'          => '0199dddd-0000-7000-8000-000000000004',
            'qty'              => 2,
            'unit_price'       => 75000,
            'disc_amount'      => 0,
            'tax_amount'       => 0,
            'subtotal'         => 150000,
        ]);
        $item->id = '0199eeee-0000-7000-8000-000000000005';
        $invoice->setRelation('items', collect([$item]));

        $expected = json_decode(json_encode($invoice->toArray()), true);
        $actual = json_decode(json_encode((new SalesInvoiceResource($invoice))->resolve()), true);

        $this->assertEquals($expected, $actual);
        $this->assertArrayHasKey('items', $actual);
        $this->assertCount(1, $actual['items']);
    }
}
