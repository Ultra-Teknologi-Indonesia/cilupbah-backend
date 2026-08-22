<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Repositories\SalesInvoiceRepository;
use Modules\Sales\Repositories\SalesPaymentRepository;
use Modules\Sales\Repositories\SalesReturnSettlementRepository;
use Tests\TestCase;

class DocumentNumberAdvisoryLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_generator_nomor_dokumen_jalan_tanpa_error_dan_format_benar(): void
    {
        $ymd = now()->format('Ymd');

        DB::transaction(function () use ($ymd) {
            $invoiceNo = app(SalesInvoiceRepository::class)->generateInvoiceNo();
            $paymentNo = app(SalesPaymentRepository::class)->generatePaymentNo();
            $settlementNo = app(SalesReturnSettlementRepository::class)->generateSettlementNo();

            $this->assertSame("INV-{$ymd}-0001", $invoiceNo);
            $this->assertSame("PAY-{$ymd}-0001", $paymentNo);
            $this->assertSame("RS-{$ymd}-0001", $settlementNo);
        });
    }

    public function test_invoice_generator_handles_overflow_beyond_9999(): void
    {
        $ymd = now()->format('Ymd');
        $location = \Modules\Warehouse\Models\Location::factory()->create();

        \Modules\Sales\Models\SalesInvoice::create([
            'invoice_number' => "INV-{$ymd}-9999",
            'customer_name'  => 'Test Customer',
            'location_id'    => $location->id,
            'status'         => 'OPEN',
            'invoice_date'   => now()->toDateString(),
            'total_amount'   => 1000,
            'paid_amount'    => 0,
            'created_by'     => 'test',
        ]);

        $nextInvoiceNo = app(SalesInvoiceRepository::class)->generateInvoiceNo();
        $this->assertSame("INV-{$ymd}-10000", $nextInvoiceNo);
    }
}
