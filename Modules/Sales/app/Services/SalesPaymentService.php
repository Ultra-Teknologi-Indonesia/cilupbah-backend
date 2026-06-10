<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
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
            $data['payment_number'] = $data['payment_number'] ?? $this->paymentRepository->generatePaymentNo();

            $payment = $this->paymentRepository->create($data);

            $invoice = SalesInvoice::lockForUpdate()->findOrFail($data['sales_invoice_id']);
            $invoice->paid_amount += $payment->amount;
            if ($invoice->paid_amount >= $invoice->total_amount) {
                $invoice->status = SalesInvoice::STATUS_PAID;
            }
            $invoice->save();

            return $payment;
        });
    }

    public function delete(string $id): void
    {
        DB::transaction(function () use ($id) {
            $payment = SalesPayment::findOrFail($id);
            $invoice = SalesInvoice::lockForUpdate()->findOrFail($payment->sales_invoice_id);

            $invoice->paid_amount = max(0, $invoice->paid_amount - $payment->amount);
            if ($invoice->status === SalesInvoice::STATUS_PAID) {
                $invoice->status = SalesInvoice::STATUS_OPEN;
            }
            $invoice->save();

            $payment->delete();
        });
    }
}
