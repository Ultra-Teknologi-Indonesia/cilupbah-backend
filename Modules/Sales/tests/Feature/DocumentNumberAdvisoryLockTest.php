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
}
