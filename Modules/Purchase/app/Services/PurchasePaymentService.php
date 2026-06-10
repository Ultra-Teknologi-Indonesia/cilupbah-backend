<?php

namespace Modules\Purchase\Services;

use Modules\Purchase\Repositories\PurchasePaymentRepository;
use Modules\Purchase\Repositories\PurchaseBillRepository;
use Modules\Purchase\Models\PurchaseBill;
use Modules\Purchase\Models\PurchasePayment;
use Illuminate\Support\Facades\DB;

class PurchasePaymentService
{
    public function __construct(
        protected PurchasePaymentRepository $paymentRepository,
        protected PurchaseBillRepository $billRepository
    ) {}

    public function getAllPaginated(int $limit = 10)
    {
        return $this->paymentRepository->getAllPaginated($limit);
    }

    public function getById(string $id): ?PurchasePayment
    {
        return $this->paymentRepository->findById($id);
    }

    public function create(array $data): PurchasePayment
    {
        return DB::transaction(function () use ($data) {
            $bill = $this->billRepository->findByIdForUpdate($data['purchase_bill_id']);
            if (! $bill) {
                throw new \Exception('Bill tidak ditemukan.');
            }

            $data['payment_number'] = $data['payment_number'] ?? $this->paymentRepository->generatePaymentNo();
            $payment = $this->paymentRepository->create($data);

            $bill->paid_amount = bcadd($bill->paid_amount, $data['amount'], 2);
            if (bccomp($bill->paid_amount, $bill->total_amount, 2) >= 0) {
                $bill->status = PurchaseBill::STATUS_PAID;
            }
            $bill->save();

            return $payment->load('bill.supplier:id,name,code');
        });
    }

    public function delete(string $id): bool
    {
        return DB::transaction(function () use ($id) {
            $payment = $this->paymentRepository->findById($id);
            if (! $payment) {
                throw new \Exception('Payment tidak ditemukan.');
            }

            $bill = $this->billRepository->findByIdForUpdate($payment->purchase_bill_id);
            if ($bill) {
                $bill->paid_amount = bcsub($bill->paid_amount, $payment->amount, 2);
                if (bccomp($bill->paid_amount, $bill->total_amount, 2) < 0 && $bill->status === PurchaseBill::STATUS_PAID) {
                    $bill->status = PurchaseBill::STATUS_OPEN;
                }
                $bill->save();
            }

            return $this->paymentRepository->delete($payment);
        });
    }
}
