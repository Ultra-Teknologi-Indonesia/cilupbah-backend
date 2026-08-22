<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesInvoice;
use Modules\Sales\Models\SalesPayment;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Repositories\SalesInvoiceRepository;

class SalesInvoiceService
{
    public function __construct(
        protected SalesInvoiceRepository $invoiceRepository,
    ) {}

    public function getAllPaginated(int $limit = 10)
    {
        return $this->invoiceRepository->getAllPaginated($limit);
    }

    public function getById(string $id): ?SalesInvoice
    {
        return $this->invoiceRepository->findById($id);
    }

    public function createOrUpdate(array $data): SalesInvoice
    {
        return DB::transaction(function () use ($data) {
            $items = $data['items'] ?? [];
            unset($data['items']);

            $existing = ! empty($data['invoice_number'])
                ? SalesInvoice::where('invoice_number', $data['invoice_number'])->first()
                : null;

            if ($existing) {

                $existing->fill($data)->save();
                $invoice = $existing;
                $invoice->items()->delete();
            } else {
                $data['invoice_number'] = $data['invoice_number'] ?? $this->invoiceRepository->generateInvoiceNo();
                $data['status'] = $data['status'] ?? SalesInvoice::STATUS_DRAFT;
                $invoice = $this->invoiceRepository->create($data);
            }

            foreach ($items as $item) {
                $item['sales_invoice_id'] = $invoice->id;
                $item['subtotal'] = $item['subtotal'] ?? (($item['qty'] * $item['unit_price']) - ($item['disc_amount'] ?? 0) + ($item['tax_amount'] ?? 0));
                $this->invoiceRepository->createItem($item);
            }

            $invoice->total_amount = $invoice->items()->sum('subtotal');
            $invoice->save();

            return $invoice->load('items');
        });
    }

    public function getUnpaid(int $limit = 10)
    {
        return $this->invoiceRepository->getUnpaid($limit);
    }

    public function getOverdue(int $limit = 10)
    {
        return $this->invoiceRepository->getOverdue($limit);
    }

    public function getSummary()
    {
        return $this->invoiceRepository->getSummary();
    }

    public function getForReturnWms(string $contactId, int $limit = 10)
    {
        return $this->invoiceRepository->getForReturnWms($contactId, $limit);
    }

    public function createFromOrder(array $data): SalesInvoice
    {
        return DB::transaction(function () use ($data) {
            $order = SalesOrder::with('items')->findOrFail($data['order_id']);

            $existing = SalesInvoice::where('order_id', $order->id)
                ->where('status', '!=', SalesInvoice::STATUS_CANCELLED)
                ->with('items')
                ->first();

            if ($existing) {
                return $existing;
            }

            $invoiceData = [
                'invoice_number' => $data['invoice_number'] ?? $this->invoiceRepository->generateInvoiceNo(),
                'order_id'       => $order->id,
                'customer_name'  => $order->customer_name,
                'location_id'    => $data['location_id'] ?? $order->location_id,
                'status'         => SalesInvoice::STATUS_OPEN,
                'invoice_date'   => now()->toDateString(),
                'due_date'       => $data['due_date'] ?? now()->addDays(30)->toDateString(),
                'total_amount'   => 0,
                'paid_amount'    => 0,
                'created_by'     => $data['created_by'],
            ];

            $invoice = $this->invoiceRepository->create($invoiceData);

            foreach ($order->items as $orderItem) {
                $subtotal = $orderItem->amount ?: ($orderItem->qty_in_base * $orderItem->price);
                $this->invoiceRepository->createItem([
                    'sales_invoice_id' => $invoice->id,
                    'item_id'          => $orderItem->item_id,
                    'qty'              => $orderItem->qty_in_base,
                    'unit_price'       => $orderItem->price,
                    'disc_amount'      => $orderItem->disc_amount ?? 0,
                    'tax_amount'       => $orderItem->tax_amount ?? 0,
                    'subtotal'         => $subtotal,
                ]);
            }

            $invoice->total_amount = $invoice->items()->sum('subtotal');
            $invoice->save();

            return $invoice->load('items');
        });
    }

    public function createFromOrderWithPayment(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $invoice = $this->createFromOrder($data);

            $payment = SalesPayment::create([
                'payment_number'   => 'PAY-' . now()->format('Ymd') . '-' . Str::upper(Str::random(4)),
                'sales_invoice_id' => $invoice->id,
                'amount'           => $invoice->total_amount,
                'payment_date'     => now()->toDateString(),
                'payment_method'   => $data['payment_method'] ?? 'CASH',
                'reference_no'     => $data['reference_no'] ?? null,
                'notes'            => $data['notes'] ?? null,
                'created_by'       => $data['created_by'],
            ]);

            $invoice->update([
                'paid_amount' => $invoice->total_amount,
                'status'      => SalesInvoice::STATUS_PAID,
            ]);

            return [
                'invoice' => $invoice->fresh('items', 'payments'),
                'payment' => $payment,
            ];
        });
    }
}
