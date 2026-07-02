<?php

namespace Modules\Finance\Services;

use Illuminate\Support\Facades\Log;
use Modules\Finance\Repositories\AccountRepository;
use Modules\Finance\Repositories\JournalRepository;
use Modules\Finance\Support\AccountMappingKey;
use Modules\Purchase\Models\PurchaseBill;
use Modules\Purchase\Models\PurchasePayment;
use Modules\Sales\Models\SalesInvoice;
use Modules\Sales\Models\SalesPayment;

class AutoJournalService
{
    public function __construct(
        protected JournalRepository $journalRepository,
        protected AccountRepository $accountRepository,
        protected AccountMappingService $accountMapping,
    ) {}

    public function forSalesInvoice(SalesInvoice $invoice): void
    {
        $this->record(
            sourceType: 'sales_invoice',
            sourceId: $invoice->id,
            sourceNo: $invoice->invoice_number,
            date: $invoice->invoice_date ?? now(),
            amount: (float) $invoice->total_amount,
            debitAccountId: $this->mappedAccountId(AccountMappingKey::ACCOUNTS_RECEIVABLE),
            creditAccountId: $this->mappedAccountId(AccountMappingKey::SALES_REVENUE),
            description: 'Penjualan ' . $invoice->invoice_number . ' — ' . ($invoice->customer_name ?? '-'),
        );
    }

    public function forSalesPayment(SalesPayment $payment): void
    {
        $this->record(
            sourceType: 'sales_payment',
            sourceId: $payment->id,
            sourceNo: $payment->payment_number,
            date: $payment->payment_date ?? now(),
            amount: (float) $payment->amount,
            debitAccountId: $this->cashAccountId($payment->payment_method),
            creditAccountId: $this->mappedAccountId(AccountMappingKey::ACCOUNTS_RECEIVABLE),
            description: 'Penerimaan ' . $payment->payment_number,
        );
    }

    public function forPurchaseBill(PurchaseBill $bill): void
    {
        $this->record(
            sourceType: 'purchase_bill',
            sourceId: $bill->id,
            sourceNo: $bill->bill_number,
            date: $bill->bill_date ?? now(),
            amount: (float) $bill->total_amount,
            debitAccountId: $this->mappedAccountId(AccountMappingKey::INVENTORY),
            creditAccountId: $this->mappedAccountId(AccountMappingKey::ACCOUNTS_PAYABLE),
            description: 'Tagihan pembelian ' . $bill->bill_number,
        );
    }

    public function forPurchasePayment(PurchasePayment $payment): void
    {
        $this->record(
            sourceType: 'purchase_payment',
            sourceId: $payment->id,
            sourceNo: $payment->payment_number,
            date: $payment->payment_date ?? now(),
            amount: (float) $payment->amount,
            debitAccountId: $this->mappedAccountId(AccountMappingKey::ACCOUNTS_PAYABLE),
            creditAccountId: $this->cashAccountId($payment->payment_method),
            description: 'Pembayaran ' . $payment->payment_number,
        );
    }

    public function forSalesInvoiceCogs(SalesInvoice $invoice): void
    {
        $totalCogs = (float) $invoice->items()->sum('total_cogs');

        $this->record(
            sourceType: 'sales_invoice_cogs',
            sourceId: $invoice->id,
            sourceNo: $invoice->invoice_number,
            date: $invoice->invoice_date ?? now(),
            amount: $totalCogs,
            debitAccountId: $this->mappedAccountId(AccountMappingKey::COGS),
            creditAccountId: $this->mappedAccountId(AccountMappingKey::INVENTORY),
            description: 'HPP penjualan ' . $invoice->invoice_number,
        );
    }

    public function forStockOpnameAdjustment(
        string $opnameNumber,
        string $sourceId,
        $date,
        float $signedValue,
    ): void {
        $amount = abs($signedValue);
        if ($amount <= 0) {
            return;
        }

        $debit = $signedValue > 0
            ? $this->mappedAccountId(AccountMappingKey::INVENTORY)
            : $this->mappedAccountId(AccountMappingKey::INVENTORY_LOSS);

        $credit = $signedValue > 0
            ? $this->mappedAccountId(AccountMappingKey::INVENTORY_GAIN)
            : $this->mappedAccountId(AccountMappingKey::INVENTORY);

        $this->record(
            sourceType: 'stock_opname',
            sourceId: $sourceId,
            sourceNo: $opnameNumber,
            date: $date,
            amount: $amount,
            debitAccountId: $debit,
            creditAccountId: $credit,
            description: 'Penyesuaian stok opname ' . $opnameNumber,
        );
    }

    public function forStockAdjustment(
        string $adjustmentNumber,
        string $sourceId,
        $date,
        float $signedValue,
    ): void {
        $amount = abs($signedValue);
        if ($amount <= 0) {
            return;
        }

        $debit = $signedValue > 0
            ? $this->mappedAccountId(AccountMappingKey::INVENTORY)
            : $this->mappedAccountId(AccountMappingKey::INVENTORY_LOSS);

        $credit = $signedValue > 0
            ? $this->mappedAccountId(AccountMappingKey::INVENTORY_GAIN)
            : $this->mappedAccountId(AccountMappingKey::INVENTORY);

        $this->record(
            sourceType: 'stock_adjustment',
            sourceId: $sourceId,
            sourceNo: $adjustmentNumber,
            date: $date,
            amount: $amount,
            debitAccountId: $debit,
            creditAccountId: $credit,
            description: 'Penyesuaian stok ' . $adjustmentNumber,
        );
    }

    public function forStockRevaluation(
        string $revalNumber,
        string $sourceId,
        $date,
        float $signedDelta,
    ): void {
        $amount = abs($signedDelta);
        if ($amount <= 0) {
            return;
        }

        $debit = $signedDelta > 0
            ? $this->mappedAccountId(AccountMappingKey::INVENTORY)
            : $this->mappedAccountId(AccountMappingKey::INVENTORY_LOSS);

        $credit = $signedDelta > 0
            ? $this->mappedAccountId(AccountMappingKey::INVENTORY_GAIN)
            : $this->mappedAccountId(AccountMappingKey::INVENTORY);

        $this->record(
            sourceType: 'stock_revaluation',
            sourceId: $sourceId,
            sourceNo: $revalNumber,
            date: $date,
            amount: $amount,
            debitAccountId: $debit,
            creditAccountId: $credit,
            description: 'Revaluasi persediaan ' . $revalNumber,
        );
    }

    public function forSalesReturnRefund(\Modules\Sales\Models\SalesReturnSettlementRefund $refund): void
    {
        $this->record(
            sourceType: 'sales_return_refund',
            sourceId: $refund->id,
            sourceNo: $refund->refund_number,
            date: $refund->refund_date ?? now(),
            amount: (float) $refund->amount,
            debitAccountId: $this->mappedAccountId(AccountMappingKey::SALES_RETURN),
            creditAccountId: $this->cashAccountId($refund->refund_method),
            description: 'Refund retur ' . $refund->refund_number,
        );
    }

    protected function record(
        string $sourceType,
        string $sourceId,
        ?string $sourceNo,
        $date,
        float $amount,
        ?string $debitAccountId,
        ?string $creditAccountId,
        string $description,
    ): void {
        if ($amount <= 0) {
            return; 
        }

        if ($this->journalRepository->existsForSourceDoc($sourceType, $sourceId)) {
            return;
        }

        if (! $debitAccountId || ! $creditAccountId) {
            Log::warning('AutoJournal dilewati: akun debit/kredit belum tersedia.', [
                'source' => "{$sourceType}:{$sourceNo}",
            ]);

            return;
        }

        $amountStr = number_format($amount, 4, '.', '');

        $this->journalRepository->createWithLines([
            'journal_no' => $this->journalRepository->nextJournalNo(),
            'transaction_date' => $date,
            'journal_type' => null, 
            'source_doc_type' => $sourceType,
            'source_doc_id' => $sourceId,
            'source_doc_no' => $sourceNo,
            'notes' => $description,
        ], [
            ['account_id' => $debitAccountId, 'debit' => $amountStr, 'credit' => 0, 'description' => $description],
            ['account_id' => $creditAccountId, 'debit' => 0, 'credit' => $amountStr, 'description' => $description],
        ]);
    }

    protected function mappedAccountId(string $key): ?string
    {
        return $this->accountMapping->resolveAccountId($key, (string) AccountMappingKey::defaultCode($key));
    }

    protected function cashAccountId(?string $paymentMethod): ?string
    {
        $map = config('finance.cashbank_accounts', []);
        $key = strtolower(trim((string) $paymentMethod));
        $code = $map[$key]['id'] ?? config('finance.cashbank_default_account.id', '1-1000');

        return $this->accountRepository->findByCode($code)?->id;
    }
}
