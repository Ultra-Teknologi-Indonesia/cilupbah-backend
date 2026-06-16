<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Modules\Sales\Exceptions\PaymentExceedsInvoiceException;
use Modules\Sales\Models\SalesInvoice;
use Modules\Sales\Models\SalesPayment;
use Modules\Sales\Repositories\SalesPaymentRepository;

class SalesPaymentService
{
    public function __construct(
        protected SalesPaymentRepository $paymentRepository,
    ) {}

    public function getAllPaginated(int $limit = 10)
    {
        return $this->paymentRepository->getAllPaginated($limit);
    }

    public function getById(string $id): ?SalesPayment
    {
        return $this->paymentRepository->findById($id);
    }

    public function create(array $data): SalesPayment
    {
        return DB::transaction(function () use ($data) {

            $invoice = SalesInvoice::lockForUpdate()->findOrFail($data['sales_invoice_id']);

            $remaining = (float) $invoice->total_amount - (float) $invoice->paid_amount;

            if ((float) $data['amount'] > $remaining) {
                throw new PaymentExceedsInvoiceException($remaining, (float) $data['amount']);
            }

            $data['payment_number'] = $data['payment_number'] ?? $this->paymentRepository->generatePaymentNo();

            $payment = $this->paymentRepository->create($data);

            $invoice->paid_amount += $payment->amount;
            $invoice->status = $this->resolveInvoiceStatus($invoice);
            $invoice->save();

            return $payment;
        });
    }

    public function delete(string $id): void
    {
        DB::transaction(function () use ($id) {
            $payment = SalesPayment::findOrFail($id);
            $invoice = SalesInvoice::lockForUpdate()->findOrFail($payment->sales_invoice_id);

            $invoice->paid_amount = max(0, (float) $invoice->paid_amount - (float) $payment->amount);
            $invoice->status = $this->resolveInvoiceStatus($invoice);
            $invoice->save();

            $payment->delete();
        });
    }

    private function resolveInvoiceStatus(SalesInvoice $invoice): string
    {
        if ($invoice->status === SalesInvoice::STATUS_CANCELLED) {
            return SalesInvoice::STATUS_CANCELLED;
        }

        return (float) $invoice->paid_amount >= (float) $invoice->total_amount
            ? SalesInvoice::STATUS_PAID
            : SalesInvoice::STATUS_OPEN;
    }
}
