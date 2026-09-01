<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Outbound\Services\CourierMappingService;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Repositories\ProductRepository;
use Modules\Product\Support\BundleStock;
use Modules\Product\Support\TechnicalSku;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderItem;
use Modules\Sales\Services\Support\SalesOrderNumberGenerator;
use Modules\Sales\Support\OrderTotals;

class SalesOrderManualService
{
    public function __construct(
        private SalesOrderNumberGenerator $numberGenerator,
        private StockService $stockService,
        private InventoryRepository $inventory,
        private ProductRepository $products,
    ) {}

    public function lookupSku(string $sku, string $locationId): ?array
    {
        $bundle = Product::with([
            'bundleItems.component.inventories.location',
            'bundleItems.component.inventories.bin',
        ])
            ->where('sku', $sku)
            ->where('is_bundle', true)
            ->where('is_active', true)
            ->first();

        if ($bundle) {
            $variant = $bundle->variants()->where('is_active', true)->first()
                ?? $this->products->ensureActiveBundleVariant($bundle);
            $stock = BundleStock::deriveByLocation($bundle)
                ?->firstWhere('location_id', $locationId);

            return [
                'item_id' => $variant->id,
                'sku' => $bundle->sku,
                'barcode' => null,
                'name' => $bundle->name,
                'sell_price' => (float) $variant->sell_price,
                'weight_gram' => (int) round(((float) ($bundle->weight ?? 0)) * 1000),
                'on_hand' => (int) ($stock['on_hand'] ?? 0),
                'on_order' => (int) ($stock['on_order'] ?? 0),
                'available' => (int) ($stock['available'] ?? 0),
                'variant' => null,
                'is_bundle' => true,
            ];
        }

        $variant = TechnicalSku::exclude(ProductVariant::with('product:id,name')->where('sku', $sku))
            ->where('is_active', true)
            ->first();

        if (! $variant) {
            return null;
        }

        $onHand = $this->inventory->sumOnHandAtLocation($variant->id, $locationId);
        $onOrder = $this->inventory->sumOnOrderAtLocation($variant->id, $locationId);
        $available = ((int) $onHand) - ((int) $onOrder);

        return [
            'item_id' => $variant->id,
            'sku' => $variant->sku,
            'barcode' => $variant->barcode,
            'name' => $variant->product?->name,
            'sell_price' => (float) $variant->sell_price,
            'weight_gram' => (int) round(((float) $variant->weight) * 1000),
            'on_hand' => (int) $onHand,
            'on_order' => (int) $onOrder,
            'available' => $available,
            'variant' => [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'label' => null,
            ],
            'is_bundle' => false,
        ];
    }

    public function create(array $payload): SalesOrder
    {
        return DB::transaction(function () use ($payload) {
            $rawNo = trim((string) ($payload['salesorder_no'] ?? ''));
            $salesOrderNo = ($rawNo === '' || strcasecmp($rawNo, '[auto]') === 0)
                ? $this->numberGenerator->nextManualSalesOrderNo()
                : $rawNo;

            $items = collect($payload['items'] ?? [])
                ->map(function (array $item): array {
                    $resolved = $this->resolveOrderableVariant($item);
                    $item['item_id'] = $resolved['variant']->id;
                    $item['sku'] = $resolved['sku'];

                    return $item;
                })
                ->all();
            $totals = $this->computeTotals($items, $payload);

            $order = SalesOrder::create([
                'salesorder_no' => $salesOrderNo,
                'no_ref' => $payload['no_ref'] ?? null,
                'transaction_date' => $payload['transaction_date'],
                'internal_store_id' => $payload['internal_store_id'],
                'salesman_id' => $payload['salesman_id'] ?? null,
                'location_id' => $payload['location_id'],
                'customer_name' => $payload['customer_name'],
                'note' => $payload['note'] ?? null,

                'is_manual' => true,
                'source' => null,
                'channel_shop_id' => null,

                'sub_total' => $totals['sub_total'],
                'total_disc' => $totals['total_disc'],
                'other_discount' => (float) ($payload['other_discount'] ?? 0),
                'total_tax' => (float) ($payload['total_tax'] ?? 0),
                'shipping_cost' => (float) ($payload['shipping_cost'] ?? 0),
                'shipping_discount' => (float) ($payload['shipping_discount'] ?? 0),
                'insurance_cost' => (float) ($payload['insurance_cost'] ?? 0),
                'service_fee' => (float) ($payload['service_fee'] ?? 0),
                'seller_voucher' => (float) ($payload['seller_voucher'] ?? 0),
                'order_processing_fee' => (float) ($payload['order_processing_fee'] ?? 0),
                'grand_total' => $totals['grand_total'],
                'price_includes_tax' => (bool) ($payload['price_includes_tax'] ?? false),

                'is_paid' => (bool) ($payload['is_paid'] ?? false),
                'is_cod' => (bool) ($payload['is_cod'] ?? false),

                'delivery_method' => $payload['delivery_method'],
                'shipping_provider' => $payload['shipping_provider'] ?? null,
                'courier_id' => ($payload['shipping_provider'] ?? null)
                    ? app(CourierMappingService::class)->resolveCourierId($payload['shipping_provider'])
                    : null,
                'resolved_shipment_type' => ($payload['shipping_provider'] ?? null)
                    ? app(CourierMappingService::class)->resolveShipmentType((string) $payload['shipping_provider'])
                    : null,
                'tracking_number' => $payload['tracking_number'] ?? null,
                'order_weight_gram' => $payload['order_weight_gram'] ?? null,

                'shipping_full_name' => $payload['shipping_full_name'] ?? $payload['customer_name'],
                'shipping_phone' => $payload['shipping_phone'] ?? null,
                'shipping_address' => $payload['shipping_address'] ?? null,
                'shipping_area' => $payload['shipping_area'] ?? null,
                'shipping_city' => $payload['shipping_city'] ?? null,
                'shipping_province' => $payload['shipping_province'] ?? null,
                'shipping_post_code' => $payload['shipping_post_code'] ?? null,
                'shipping_country' => $payload['shipping_country'] ?? 'ID',
                'shipping_coordinate' => $payload['shipping_coordinate'] ?? null,

                'status' => 'reserved',
                'is_canceled' => false,
            ]);

            foreach ($items as $item) {
                $qty = (int) $item['qty_in_base'];
                $price = (float) $item['price'];
                $percent = (float) ($item['disc_percent'] ?? 0);
                $flat = (float) ($item['disc'] ?? 0);
                $tax = (float) ($item['tax_amount'] ?? 0);
                $gross = $qty * $price;
                $disc = $flat > 0 ? $flat : ($gross * $percent / 100);
                $amount = max(0, $gross - $disc);

                SalesOrderItem::create([
                    'order_id' => $order->id,
                    'item_id' => $item['item_id'],
                    'sku' => $item['sku'],
                    'description' => $item['description'] ?? null,
                    'qty_in_base' => $qty,
                    'price' => $price,
                    'disc' => $disc,

                    'disc_amount' => $disc,
                    'tax_amount' => $tax,
                    'amount' => $amount,
                ]);

                $this->stockService->reserve(
                    sku: (string) $item['sku'],
                    itemId: (string) $item['item_id'],
                    locationId: (string) $payload['location_id'],
                    qty: $qty,
                    transactionNumber: $order->salesorder_no,
                    enforce: false,
                );
            }

            return $order->fresh(['items', 'internalStore', 'salesman']);
        });
    }

    private function resolveOrderableVariant(array $item): array
    {
        $itemId = (string) ($item['item_id'] ?? '');
        $sku = trim((string) ($item['sku'] ?? ''));

        $selectedVariant = ProductVariant::with('product:id,sku,is_bundle')
            ->where('id', $itemId)
            ->where('is_active', true)
            ->first();

        if ($selectedVariant && ! $selectedVariant->product?->is_bundle) {
            return ['variant' => $selectedVariant, 'sku' => (string) $selectedVariant->sku];
        }

        $bundle = Product::query()
            ->where('is_bundle', true)
            ->where('is_active', true)
            ->where(function ($query) use ($itemId, $sku) {
                $query->where('id', $itemId);
                if ($sku !== '') {
                    $query->orWhere('sku', $sku);
                }
            })
            ->first();

        if ($bundle) {
            $variant = $bundle->variants()->where('is_active', true)->first()
                ?? $this->products->ensureActiveBundleVariant($bundle);

            return ['variant' => $variant, 'sku' => (string) $bundle->sku];
        }

        $variant = $selectedVariant;

        if (! $variant || $variant->product?->is_bundle) {
            throw new \DomainException('Item yang dipilih sudah tidak tersedia atau tidak valid.');
        }

        return ['variant' => $variant, 'sku' => (string) $variant->sku];
    }

    private function computeTotals(array $items, array $payload): array
    {
        $subTotal = 0.0;
        $totalDisc = 0.0;

        foreach ($items as $item) {
            $qty = (int) $item['qty_in_base'];
            $price = (float) $item['price'];
            $percent = (float) ($item['disc_percent'] ?? 0);
            $flat = (float) ($item['disc'] ?? 0);
            $gross = $qty * $price;
            $disc = $flat > 0 ? $flat : ($gross * $percent / 100);
            $subTotal += $gross;
            $totalDisc += $disc;
        }

        $grand = OrderTotals::grandTotal([
            'sub_total' => $subTotal,
            'total_disc' => $totalDisc,
            'other_discount' => (float) ($payload['other_discount'] ?? 0),
            'total_tax' => (float) ($payload['total_tax'] ?? 0),
            'shipping_cost' => (float) ($payload['shipping_cost'] ?? 0),
            'shipping_discount' => (float) ($payload['shipping_discount'] ?? 0),
            'insurance_cost' => (float) ($payload['insurance_cost'] ?? 0),
            'service_fee' => (float) ($payload['service_fee'] ?? 0),
            'seller_voucher' => (float) ($payload['seller_voucher'] ?? 0),
            'order_processing_fee' => (float) ($payload['order_processing_fee'] ?? 0),
            'price_includes_tax' => (bool) ($payload['price_includes_tax'] ?? false),
        ]);

        return [
            'sub_total' => round($subTotal, 2),
            'total_disc' => round($totalDisc, 2),
            'grand_total' => $grand,
        ];
    }
}
