<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Product\Models\ProductVariant;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderItem;
use Modules\Sales\Services\StockService;
use Modules\Sales\Services\Support\SalesOrderNumberGenerator;

class SalesOrderManualService
{
    public function __construct(
        private SalesOrderNumberGenerator $numberGenerator,
        private StockService $stockService,
        private InventoryRepository $inventory,
    ) {}

    public function lookupSku(string $sku, string $locationId): ?array
    {
        $variant = ProductVariant::with('product:id,name')
            ->where('sku', $sku)
            ->where('is_active', true)
            ->first();

        if (! $variant) {
            return null;
        }

        $onHand  = $this->inventory->sumOnHandAtLocation($variant->id, $locationId);
        $onOrder = $this->inventory->sumOnOrderAtLocation($variant->id, $locationId);
        $available = ((int) $onHand) - ((int) $onOrder);

        return [
            'item_id'     => $variant->id,
            'sku'         => $variant->sku,
            'barcode'     => $variant->barcode,
            'name'        => $variant->product?->name,
            'sell_price'  => (float) $variant->sell_price,
            'weight_gram' => (int) round(((float) $variant->weight) * 1000),
            'on_hand'     => (int) $onHand,
            'on_order'    => (int) $onOrder,
            'available'   => $available,
        ];
    }

    public function create(array $payload): SalesOrder
    {
        return DB::transaction(function () use ($payload) {
            $rawNo = trim((string) ($payload['salesorder_no'] ?? ''));
            $salesOrderNo = ($rawNo === '' || strcasecmp($rawNo, '[auto]') === 0)
                ? $this->numberGenerator->nextManualSalesOrderNo()
                : $rawNo;

            $items    = $payload['items'] ?? [];
            $totals   = $this->computeTotals($items, $payload);

            $order = SalesOrder::create([
                'salesorder_no'       => $salesOrderNo,
                'no_ref'              => $payload['no_ref'] ?? null,
                'transaction_date'    => $payload['transaction_date'],
                'internal_store_id'   => $payload['internal_store_id'],
                'salesman_id'         => $payload['salesman_id'] ?? null,
                'location_id'         => $payload['location_id'],
                'customer_name'       => $payload['customer_name'],
                'note'                => $payload['note'] ?? null,

                'is_manual'           => true,
                'source'              => null,
                'channel_shop_id'     => null,

                'sub_total'           => $totals['sub_total'],
                'total_disc'          => $totals['total_disc'],
                'other_discount'      => (float) ($payload['other_discount'] ?? 0),
                'total_tax'           => (float) ($payload['total_tax'] ?? 0),
                'shipping_cost'       => (float) ($payload['shipping_cost'] ?? 0),
                'shipping_discount'   => (float) ($payload['shipping_discount'] ?? 0),
                'insurance_cost'      => (float) ($payload['insurance_cost'] ?? 0),
                'service_fee'         => (float) ($payload['service_fee'] ?? 0),
                'seller_voucher'      => (float) ($payload['seller_voucher'] ?? 0),
                'order_processing_fee' => (float) ($payload['order_processing_fee'] ?? 0),
                'grand_total'         => $totals['grand_total'],
                'price_includes_tax'  => (bool) ($payload['price_includes_tax'] ?? false),

                'is_paid'             => (bool) ($payload['is_paid'] ?? false),
                'is_cod'              => (bool) ($payload['is_cod'] ?? false),
                'is_jubelio_shipment' => (bool) ($payload['is_jubelio_shipment'] ?? false),

                'delivery_method'     => $payload['delivery_method'],
                'shipping_provider'   => $payload['shipping_provider'] ?? null,
                'tracking_number'     => $payload['tracking_number'] ?? null,
                'order_weight_gram'   => $payload['order_weight_gram'] ?? null,

                'shipping_full_name'  => $payload['shipping_full_name'] ?? $payload['customer_name'],
                'shipping_phone'      => $payload['shipping_phone'] ?? null,
                'shipping_address'    => $payload['shipping_address'] ?? null,
                'shipping_area'       => $payload['shipping_area'] ?? null,
                'shipping_city'       => $payload['shipping_city'] ?? null,
                'shipping_province'   => $payload['shipping_province'] ?? null,
                'shipping_post_code'  => $payload['shipping_post_code'] ?? null,
                'shipping_country'    => $payload['shipping_country'] ?? 'ID',
                'shipping_coordinate' => $payload['shipping_coordinate'] ?? null,

                'status'              => 'reserved',
                'is_canceled'         => false,
            ]);

            foreach ($items as $item) {
                $qty     = (int) $item['qty_in_base'];
                $price   = (float) $item['price'];
                $percent = (float) ($item['disc_percent'] ?? 0);
                $flat    = (float) ($item['disc'] ?? 0);
                $tax     = (float) ($item['tax_amount'] ?? 0);
                $gross   = $qty * $price;
                $disc    = $flat > 0 ? $flat : ($gross * $percent / 100);
                $amount  = max(0, $gross - $disc);

                SalesOrderItem::create([
                    'order_id'     => $order->id,
                    'item_id'      => $item['item_id'],
                    'sku'          => $item['sku'],
                    'description'  => $item['description'] ?? null,
                    'qty_in_base'  => $qty,
                    'price'        => $price,
                    'disc'         => $disc,
                    'tax_amount'   => $tax,
                    'amount'       => $amount,
                ]);

                $this->stockService->reserve(
                    sku: (string) $item['sku'],
                    itemId: (string) $item['item_id'],
                    locationId: (string) $payload['location_id'],
                    qty: $qty,
                    transactionNumber: $order->salesorder_no,
                    enforce: true,
                );
            }

            return $order->fresh(['items', 'internalStore', 'salesman']);
        });
    }

    private function computeTotals(array $items, array $payload): array
    {
        $subTotal   = 0.0;
        $totalDisc  = 0.0;

        foreach ($items as $item) {
            $qty     = (int) $item['qty_in_base'];
            $price   = (float) $item['price'];
            $percent = (float) ($item['disc_percent'] ?? 0);
            $flat    = (float) ($item['disc'] ?? 0);
            $gross   = $qty * $price;
            $disc    = $flat > 0 ? $flat : ($gross * $percent / 100);
            $subTotal  += $gross;
            $totalDisc += $disc;
        }

        $otherDisc     = (float) ($payload['other_discount'] ?? 0);
        $tax           = (float) ($payload['total_tax'] ?? 0);
        $ship          = (float) ($payload['shipping_cost'] ?? 0);
        $shipDisc      = (float) ($payload['shipping_discount'] ?? 0);
        $insurance     = (float) ($payload['insurance_cost'] ?? 0);
        $serviceFee    = (float) ($payload['service_fee'] ?? 0);
        $sellerVoucher = (float) ($payload['seller_voucher'] ?? 0);
        $procFee       = (float) ($payload['order_processing_fee'] ?? 0);
        $priceIncTax   = (bool) ($payload['price_includes_tax'] ?? false);

        $net = $subTotal - $totalDisc - $otherDisc;
        if (!$priceIncTax) {
            $net += $tax;
        }
        $additional = $serviceFee - $sellerVoucher + $insurance + $procFee;
        $grand = $net + max(0, $ship - $shipDisc) + $additional;

        return [
            'sub_total'   => round($subTotal, 2),
            'total_disc'  => round($totalDisc, 2),
            'grand_total' => round(max(0, $grand), 2),
        ];
    }
}
