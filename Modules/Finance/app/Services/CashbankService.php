<?php

namespace Modules\Finance\Services;

use Illuminate\Database\Eloquent\Model;
use Modules\Finance\Http\Resources\CashbankDetailResource;
use Modules\Finance\Http\Resources\CashbankResource;
use Modules\Finance\Repositories\CashbankRepository;
use Modules\Purchase\Models\PurchasePayment;
use Modules\Sales\Models\SalesPayment;

class CashbankService
{
    public function __construct(
        protected CashbankRepository $repository,
    ) {}

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

    public function mapItem(Model $payment): array
    {
        $account = $this->resolveAccount($payment->payment_method);
        [$contactId, $contactName] = $this->resolveContact($payment);

        return (new CashbankResource($payment, [
            'account' => $account,
            'contact_id' => $contactId,
            'contact_name' => $contactName,
            'doc_type' => $this->docType($payment),
        ]))->toArray(request());
    }

    public function mapDetail(Model $payment): array
    {
        $account = $this->resolveAccount($payment->payment_method);
        [$contactId, $contactName] = $this->resolveContact($payment);

        return (new CashbankDetailResource($payment, [
            'account' => $account,
            'contact_id' => $contactId,
            'contact_name' => $contactName,
            'doc_type' => $this->docType($payment),
            'lines' => $this->journalLinesFor($payment, $account),
        ]))->toArray(request());
    }

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

    protected function synthesizeJournalLines(Model $payment, array $cashAccount): array
    {
        $amount = number_format((float) $payment->amount, 4, '.', '');
        $zero = '0.0000';

        if ($payment instanceof SalesPayment) {

            $counter = config('finance.counter_accounts.receivable');

            return [
                $this->journalLine($cashAccount, debit: $amount, credit: $zero, description: 'Penerimaan ' . $payment->payment_number),
                $this->journalLine($counter, debit: $zero, credit: $amount, description: 'Pelunasan piutang ' . ($payment->invoice?->invoice_number ?? '-')),
            ];
        }

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
            'journal_detail_id' => null, 
        ];
    }

    protected function docType(Model $payment): string
    {
        return $payment instanceof SalesPayment ? 'Penerimaan' : 'Pembayaran';
    }

    protected function resolveAccount(?string $paymentMethod): array
    {
        $map = config('finance.cashbank_accounts', []);
        $key = strtolower(trim((string) $paymentMethod));

        return $map[$key] ?? config('finance.cashbank_default_account', ['id' => '1-1000', 'name' => '1-1000 - Kas']);
    }

    protected function resolveContact(Model $payment): array
    {
        if ($payment instanceof SalesPayment) {
            return [$payment->invoice?->id, $payment->invoice?->customer_name];
        }

        return [$payment->bill?->supplier?->id, $payment->bill?->supplier?->name];
    }

    protected function isValidUuid(string $id): bool
    {
        $normalized = str_replace('-', '', $id);

        return strlen($normalized) === 32 && ctype_xdigit($normalized);
    }
}
