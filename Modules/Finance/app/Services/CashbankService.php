<?php

namespace Modules\Finance\Services;

use Illuminate\Database\Eloquent\Model;
use Modules\Finance\Repositories\CashbankRepository;
use Modules\Purchase\Models\PurchasePayment;
use Modules\Sales\Models\SalesPayment;

/**
 * Business logic Cash & Bank (setara Jubelio getPayments/getReceives + detail by id).
 *
 * - doc_type: SalesPayment = "Penerimaan" (uang masuk), PurchasePayment = "Pembayaran" (uang keluar).
 * - Akun kas/bank dipetakan dari payment_method (config finance.cashbank_accounts, fallback Kas).
 * - Detail menyertakan accounts[] = jurnal dua-baris DISINTESIS (seimbang secara akuntansi):
 *     Penerimaan: Dr Kas/Bank — Cr Piutang Usaha
 *     Pembayaran: Dr Hutang Usaha — Cr Kas/Bank
 *   journal_detail_id = null (GL nyata belum ada — domain Journal). Saat COA/GL dibangun,
 *   bagian ini diganti query jurnal sungguhan tanpa mengubah kontrak response.
 */
class CashbankService
{
    public function __construct(
        protected CashbankRepository $repository,
    ) {}

    // ==================== Listing ====================

    public function listPayments(?string $dateFrom = null, ?string $dateTo = null)
    {
        return $this->repository->paginatePayments($dateFrom, $dateTo)
            ->through(fn (PurchasePayment $p) => $this->mapItem($p));
    }

    public function listReceives(?string $dateFrom = null, ?string $dateTo = null)
    {
        return $this->repository->paginateReceives($dateFrom, $dateTo)
            ->through(fn (SalesPayment $p) => $this->mapItem($p));
    }

    // ==================== Detail ====================

    public function paymentDetail(string $id): ?array
    {
        if (! $this->isValidUuid($id)) {
            return null;
        }

        $payment = $this->repository->findPayment($id);

        return $payment ? $this->mapDetail($payment) : null;
    }

    public function receiveDetail(string $id): ?array
    {
        if (! $this->isValidUuid($id)) {
            return null;
        }

        $payment = $this->repository->findReceive($id);

        return $payment ? $this->mapDetail($payment) : null;
    }

    // ==================== Mapping (bentuk setara Jubelio) ====================

    /** Item list: getCashbankReceivesResponse.data[] Jubelio. */
    public function mapItem(Model $payment): array
    {
        $account = $this->resolveAccount($payment->payment_method);
        [$contactId, $contactName] = $this->resolveContact($payment);

        return [
            'account_id' => $account['id'],
            'account_name' => $account['name'],
            'amount' => (string) $payment->amount,
            'contact_id' => $contactId,
            'contact_name' => $contactName,
            'doc_type' => $this->docType($payment),
            'payment_id' => $payment->id,
            'payment_no' => $payment->payment_number,
            'transaction_date' => $payment->payment_date?->toIso8601String(),
        ];
    }

    /** Detail: getCashbankPaymentByIdResponse / getCashbankReceiveByIdResponse Jubelio. */
    public function mapDetail(Model $payment): array
    {
        $account = $this->resolveAccount($payment->payment_method);
        [$contactId, $contactName] = $this->resolveContact($payment);

        return [
            'payment_id' => $payment->id,
            'payment_no' => $payment->payment_number,
            'payment_type' => $this->docType($payment),
            'amount' => (string) $payment->amount,
            'cashbank_account_id' => $account['id'],
            'cashbank_account_name' => $account['name'],
            'contact_id' => $contactId,
            'contact_name' => $contactName,
            'note' => $payment->notes ?? '',
            'reference_no' => $payment->reference_no,
            'transaction_date' => $payment->payment_date?->toIso8601String(),
            'accounts' => $this->journalLinesFor($payment, $account),
        ];
    }

    /**
     * Baris jurnal untuk detail cashbank (PLAN-CASHBANK §7 / PLAN-JOURNAL §1e):
     * pakai JURNAL NYATA bila sudah ada (journal_detail_id terisi); data lama
     * pra-domain-Journal → fallback sintesis. Kontrak response tidak berubah.
     */
    protected function journalLinesFor(Model $payment, array $cashAccount): array
    {
        $sourceType = $payment instanceof SalesPayment ? 'sales_payment' : 'purchase_payment';

        $journal = app(\Modules\Finance\Repositories\JournalRepository::class)
            ->findBySourceDoc($sourceType, $payment->id);

        if ($journal) {
            return $journal->details->map(fn ($d) => [
                'account_id' => $d->account?->account_code ?? $d->account_id,
                'account_name' => $d->account?->display_name,
                'debit' => (string) $d->debit,
                'credit' => (string) $d->credit,
                'description' => $d->description,
                'journal_detail_id' => $d->id,
            ])->all();
        }

        return $this->synthesizeJournalLines($payment, $cashAccount);
    }

    /**
     * Jurnal dua-baris sintetis yang SEIMBANG (total debit = total kredit = amount).
     */
    protected function synthesizeJournalLines(Model $payment, array $cashAccount): array
    {
        $amount = number_format((float) $payment->amount, 4, '.', '');
        $zero = '0.0000';

        if ($payment instanceof SalesPayment) {
            // Uang masuk: Dr Kas/Bank — Cr Piutang Usaha.
            $counter = config('finance.counter_accounts.receivable');

            return [
                $this->journalLine($cashAccount, debit: $amount, credit: $zero, description: 'Penerimaan ' . $payment->payment_number),
                $this->journalLine($counter, debit: $zero, credit: $amount, description: 'Pelunasan piutang ' . ($payment->invoice?->invoice_number ?? '-')),
            ];
        }

        // Uang keluar: Dr Hutang Usaha — Cr Kas/Bank.
        $counter = config('finance.counter_accounts.payable');

        return [
            $this->journalLine($counter, debit: $amount, credit: $zero, description: 'Pelunasan hutang ' . ($payment->bill?->bill_number ?? '-')),
            $this->journalLine($cashAccount, debit: $zero, credit: $amount, description: 'Pembayaran ' . $payment->payment_number),
        ];
    }

    protected function journalLine(array $account, string $debit, string $credit, string $description): array
    {
        return [
            'account_id' => $account['id'],
            'account_name' => $account['name'],
            'debit' => $debit,
            'credit' => $credit,
            'description' => $description,
            'journal_detail_id' => null, // GL nyata belum ada (domain Journal).
        ];
    }

    // ==================== Resolusi ====================

    protected function docType(Model $payment): string
    {
        return $payment instanceof SalesPayment ? 'Penerimaan' : 'Pembayaran';
    }

    /** payment_method → akun kas/bank; tidak dikenal/null → fallback Kas. */
    protected function resolveAccount(?string $paymentMethod): array
    {
        $map = config('finance.cashbank_accounts', []);
        $key = strtolower(trim((string) $paymentMethod));

        return $map[$key] ?? config('finance.cashbank_default_account', ['id' => '1-1000', 'name' => '1-1000 - Kas']);
    }

    /**
     * Kontak transaksi. Null-safe: relasi yatim (invoice/bill/supplier terhapus)
     * menghasilkan null, bukan error.
     */
    protected function resolveContact(Model $payment): array
    {
        if ($payment instanceof SalesPayment) {
            return [$payment->invoice?->id, $payment->invoice?->customer_name];
        }

        /** @var PurchasePayment $payment */
        return [$payment->bill?->supplier?->id, $payment->bill?->supplier?->name];
    }

    /** Guard format UUID (cegah error cast uuid Postgres → 404, bukan 500). */
    protected function isValidUuid(string $id): bool
    {
        $normalized = str_replace('-', '', $id);

        return strlen($normalized) === 32 && ctype_xdigit($normalized);
    }
}
