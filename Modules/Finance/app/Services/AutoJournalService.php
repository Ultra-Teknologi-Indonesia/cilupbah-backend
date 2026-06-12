<?php

namespace Modules\Finance\Services;

use Illuminate\Support\Facades\Log;
use Modules\Finance\Models\Account;
use Modules\Finance\Repositories\AccountRepository;
use Modules\Finance\Repositories\JournalRepository;
use Modules\Purchase\Models\PurchaseBill;
use Modules\Purchase\Models\PurchasePayment;
use Modules\Sales\Models\SalesInvoice;
use Modules\Sales\Models\SalesPayment;

/**
 * Jurnal otomatis dari dokumen sumber (dipanggil observer, PLAN-JOURNAL.md §1c).
 *
 * Sifat:
 * - Berjalan di dalam transaksi DB dokumen sumber → atomik.
 * - Idempoten: unique (source_doc_type, source_doc_id) + cek exists.
 * - FAIL-OPEN: COA belum di-seed / amount ≤ 0 → skip + log, TIDAK melempar
 *   (kegagalan jurnal tidak boleh menggagalkan transaksi bisnis Rasyid).
 */
class AutoJournalService
{
    public function __construct(
        protected JournalRepository $journalRepository,
        protected AccountRepository $accountRepository,
    ) {}

    /** SalesInvoice: Dr Piutang Usaha — Cr Pendapatan Penjualan. */
    public function forSalesInvoice(SalesInvoice $invoice): void
    {
        $this->record(
            sourceType: 'sales_invoice',
            sourceId: $invoice->id,
            sourceNo: $invoice->invoice_number,
            date: $invoice->invoice_date ?? now(),
            amount: (float) $invoice->total_amount,
            debitCode: '1-1100',
            creditCode: '4-4000',
            description: 'Penjualan ' . $invoice->invoice_number . ' — ' . ($invoice->customer_name ?? '-'),
        );
    }

    /** SalesPayment: Dr Kas/Bank (map payment_method) — Cr Piutang Usaha. */
    public function forSalesPayment(SalesPayment $payment): void
    {
        $this->record(
            sourceType: 'sales_payment',
            sourceId: $payment->id,
            sourceNo: $payment->payment_number,
            date: $payment->payment_date ?? now(),
            amount: (float) $payment->amount,
            debitCode: $this->cashAccountCode($payment->payment_method),
            creditCode: '1-1100',
            description: 'Penerimaan ' . $payment->payment_number,
        );
    }

    /** PurchaseBill: Dr Persediaan Barang — Cr Hutang Usaha. */
    public function forPurchaseBill(PurchaseBill $bill): void
    {
        $this->record(
            sourceType: 'purchase_bill',
            sourceId: $bill->id,
            sourceNo: $bill->bill_number,
            date: $bill->bill_date ?? now(),
            amount: (float) $bill->total_amount,
            debitCode: '1-1200',
            creditCode: '2-2000',
            description: 'Tagihan pembelian ' . $bill->bill_number,
        );
    }

    /** PurchasePayment: Dr Hutang Usaha — Cr Kas/Bank. */
    public function forPurchasePayment(PurchasePayment $payment): void
    {
        $this->record(
            sourceType: 'purchase_payment',
            sourceId: $payment->id,
            sourceNo: $payment->payment_number,
            date: $payment->payment_date ?? now(),
            amount: (float) $payment->amount,
            debitCode: '2-2000',
            creditCode: $this->cashAccountCode($payment->payment_method),
            description: 'Pembayaran ' . $payment->payment_number,
        );
    }

    // ==================== Inti ====================

    protected function record(
        string $sourceType,
        string $sourceId,
        ?string $sourceNo,
        $date,
        float $amount,
        string $debitCode,
        string $creditCode,
        string $description,
    ): void {
        if ($amount <= 0) {
            return; // tidak ada nilai uang — tidak ada jurnal
        }

        // Idempoten (lapisan pertama; unique index = lapisan kedua).
        if ($this->journalRepository->existsForSourceDoc($sourceType, $sourceId)) {
            return;
        }

        $debitAccount = $this->accountRepository->findByCode($debitCode);
        $creditAccount = $this->accountRepository->findByCode($creditCode);

        if (! $debitAccount || ! $creditAccount) {
            Log::warning("AutoJournal dilewati: akun {$debitCode}/{$creditCode} belum di-seed.", [
                'source' => "{$sourceType}:{$sourceNo}",
            ]);

            return;
        }

        $amountStr = number_format($amount, 4, '.', '');

        $this->journalRepository->createWithLines([
            'journal_no' => $this->journalRepository->nextJournalNo(),
            'transaction_date' => $date,
            'journal_type' => null, // jurnal otomatis (kontrak Jubelio: null)
            'source_doc_type' => $sourceType,
            'source_doc_id' => $sourceId,
            'source_doc_no' => $sourceNo,
            'notes' => $description,
        ], [
            ['account_id' => $debitAccount->id, 'debit' => $amountStr, 'credit' => 0, 'description' => $description],
            ['account_id' => $creditAccount->id, 'debit' => 0, 'credit' => $amountStr, 'description' => $description],
        ]);
    }

    /** payment_method → kode akun kas/bank (reuse map cashbank; fallback Kas). */
    protected function cashAccountCode(?string $paymentMethod): string
    {
        $map = config('finance.cashbank_accounts', []);
        $key = strtolower(trim((string) $paymentMethod));

        return $map[$key]['id'] ?? config('finance.cashbank_default_account.id', '1-1000');
    }
}
