<?php

namespace Modules\Report\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Modules\Report\Repositories\ReportRepository;
use Modules\Sales\Models\SalesOrder;

class ReportService
{
    public function __construct(
        protected ReportRepository $repository
    ) {}

    public function putawayReport(array $filters): array
    {
        return $this->wrap($this->repository->putaway($filters), 'putaway');
    }

    public function receiveBillReport(array $filters): array
    {
        return $this->wrap($this->repository->receiveBill($filters), 'receive_bill');
    }

    public function adjustmentReport(array $filters): array
    {
        return $this->wrap($this->repository->adjustment($filters), 'stock_adjustment');
    }

    public function stockOpnameReport(array $filters): array
    {
        return $this->wrap($this->repository->stockOpname($filters), 'stock_opname');
    }

    public function purchaseOrderReport(array $filters): array
    {
        return $this->wrap($this->repository->purchaseOrder($filters), 'purchase_order');
    }

    public function invoiceReport(array $filters): array
    {
        $orderIds = $this->normalizeOrderIds($filters['order_ids'] ?? null);
        $filters['order_ids'] = $orderIds;

        $result = $this->repository->invoice($filters);

        if (! $result instanceof LengthAwarePaginator) {
            return $this->wrapSingle($result, 'invoice');
        }

        $wrapped = $this->wrapCollection($result, 'invoice');

        if (empty($wrapped['data']) && ! empty($orderIds)) {
            $orders = $this->repository->invoiceFallbackOrders($orderIds);

            $wrapped['data'] = $orders->map(fn ($order) => [
                'invoice_number' => 'INV-' . $order->salesorder_no,
                'invoice_date'   => $order->transaction_date ?? now()->toDateString(),
                'status'         => $order->is_paid ? 'PAID' : 'OPEN',
                'customer_name'  => $order->customer_name,
                'total_amount'   => $order->grand_total,
                'paid_amount'    => $order->is_paid ? $order->grand_total : 0,
                'order'          => [
                    'id'              => $order->id,
                    'salesorder_no'   => $order->salesorder_no,
                    'customer_name'   => $order->customer_name,
                    'shipping_full_name' => $order->shipping_full_name,
                    'shipping_address'   => $order->shipping_address,
                    'shipping_city'      => $order->shipping_city,
                ],
                'items' => $order->items->map(fn ($item) => [
                    'sku'         => $item->sku,
                    'description' => $item->description,
                    'qty_in_base' => $item->qty_in_base,
                    'price'       => $item->price,
                    'amount'      => $item->amount ?: ($item->qty_in_base * $item->price),
                ])->toArray(),
            ])->toArray();
        }

        return $wrapped;
    }

    public function consignReport(array $filters): array
    {
        return $this->wrap($this->repository->consign($filters), 'consign_bill');
    }

    public function itemReceiveNotPlaceReport(array $filters): array
    {
        $paginated = $this->repository->itemReceiveNotPlace($filters);

        $paginated->getCollection()->transform(function ($inbound) {
            $inbound->setRelation('items', $inbound->items->filter(
                fn ($item) => $item->putaway_qty < $item->received_qty
            )->values());
            return $inbound;
        });

        return $this->wrapCollection($paginated, 'item_receive_not_place');
    }

    public function pickListReport(array $filters): array
    {
        $filters['order_ids'] = $this->normalizeOrderIds($filters['order_ids'] ?? null);

        return $this->wrap($this->repository->pickList($filters), 'pick_list');
    }

    public function shippingManifestReport(array $filters): array
    {
        $filters['order_ids'] = $this->normalizeOrderIds($filters['order_ids'] ?? null);

        return $this->wrap($this->repository->shippingManifest($filters), 'shipping_manifest');
    }

    public function hppReport(string $dateFrom, string $dateTo, ?string $locationId = null): array
    {
        $agg = $this->repository->hppAggregates($dateFrom, $dateTo, $locationId);

        $persediaanAkhir    = $agg['persediaan_akhir'];
        $pembelianBruto     = $agg['pembelian_bruto'];
        $ongkosAngkut       = $agg['ongkos_angkut'];
        $potonganPembelian  = $agg['potongan_pembelian'];
        $returPembelian     = $agg['retur_pembelian'];
        $hppPeriode         = $agg['hpp_periode'];

        $pembelianBersih = $pembelianBruto + $ongkosAngkut - $returPembelian - $potonganPembelian;
        $persediaanAwal  = $persediaanAkhir - $pembelianBersih + $hppPeriode;
        $hpp             = $persediaanAwal + $pembelianBersih - $persediaanAkhir;

        return [
            'report_type'  => 'hpp',
            'generated_at' => now()->toIso8601String(),
            'period'       => [
                'date_from'   => $dateFrom,
                'date_to'     => $dateTo,
                'location_id' => $locationId,
            ],
            'data' => [
                'persediaan_awal'   => round($persediaanAwal, 2),
                'pembelian_bruto'   => round($pembelianBruto, 2),
                'ongkos_angkut'     => round($ongkosAngkut, 2),
                'retur_pembelian'   => round($returPembelian, 2),
                'potongan_pembelian'=> round($potonganPembelian, 2),
                'pembelian_bersih'  => round($pembelianBersih, 2),
                'barang_tersedia'   => round($persediaanAwal + $pembelianBersih, 2),
                'persediaan_akhir'  => round($persediaanAkhir, 2),
                'hpp'               => round($hpp, 2),
                'hpp_periode_snapshot' => round($hppPeriode, 2),
            ],
        ];
    }

    public function barcodePdf(array $data)
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(120);

        $jenis = $data['jenis'];
        $ids = $data['ids'];
        $harga = $data['harga'];

        $variants = $this->repository->barcodeVariants($jenis, $ids);

        $onlineMappings = collect();
        if ($harga === 'online' && $variants->isNotEmpty()) {
            $onlineMappings = $this->repository->barcodeOnlineMappings($variants->pluck('id'));
        }

        $cells = [];
        $qrCache = [];

        foreach ($variants as $variant) {
            $sku = (string) $variant->sku;
            if ($sku === '') {
                continue;
            }
            $name = $variant->product?->name ?? '-';

            if (! isset($qrCache[$sku])) {
                $qrCache[$sku] = $this->qrDataUri($sku);
            }
            $qr = $qrCache[$sku];

            if ($harga === 'online') {
                $variantMappings = $onlineMappings->get($variant->id, collect());
                if ($variantMappings->isEmpty()) {
                    continue;
                }
                foreach ($variantMappings as $mapping) {
                    $shop = $mapping->channelMapping?->channelShop;
                    $price = $mapping->synced_price ?? $variant->sell_price;
                    $cells[] = [
                        'sku' => $sku,
                        'name' => $name,
                        'qr' => $qr,
                        'store_name' => $shop?->shop_name,
                        'price' => $price !== null ? (float) $price : null,
                    ];
                }
            } else {
                $cells[] = [
                    'sku' => $sku,
                    'name' => $name,
                    'qr' => $qr,
                    'store_name' => null,
                    'price' => $harga === 'default' && $variant->sell_price !== null
                        ? (float) $variant->sell_price
                        : null,
                ];
            }
        }

        $pdf = Pdf::loadView('report::pdf.barcode', [
            'cells' => $cells,
            'mode' => $harga,
        ]);
        $pdf->setPaper('a4', 'portrait');

        return $pdf;
    }

    private function qrDataUri(string $content): ?string
    {
        try {
            $svg = QrCode::format('svg')
                ->size(120)
                ->margin(0)
                ->errorCorrection('M')
                ->generate($content);

            return 'data:image/svg+xml;base64,' . base64_encode((string) $svg);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    public function penyesuaianStokBuild(array $data)
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(120);

        $startDate = $data['start_date'];
        $endDate = $data['end_date'];
        $productIds = $data['product_ids'] ?? [];
        $locationIds = $data['location_ids'] ?? [];

        $adjustments = $this->repository->penyesuaianAdjustments($startDate, $endDate, $productIds, $locationIds);

        $rows = collect();

        foreach ($adjustments as $adjustment) {
            foreach ($adjustment->items as $item) {
                $variant = $item->product;
                if (! $variant) {
                    continue;
                }

                $rows->push([
                    'sku' => $variant->sku,
                    'name' => $variant->product?->name ?? '-',
                    'date' => $adjustment->transaction_date,
                    'source' => $adjustment->adjustment_no,
                    'note' => $item->notes ?: $adjustment->notes,
                    'qty' => (float) $item->difference_qty,
                ]);
            }
        }

        $groups = $rows->groupBy('sku')
            ->map(function ($items, $sku) {
                $sorted = $items->sortBy('date')->values();

                return [
                    'sku' => $sku,
                    'name' => $sorted->first()['name'],
                    'unit' => 'Buah',
                    'rows' => $sorted->all(),
                    'total' => $sorted->sum('qty'),
                ];
            })
            ->sortKeys()
            ->values();

        $pdf = Pdf::loadView('report::pdf.penyesuaian-stok', [
            'groups' => $groups,
            'start' => $startDate,
            'end' => $endDate,
        ]);
        $pdf->setPaper('a4', 'portrait');

        return $pdf;
    }

    public function lazadaOrder(string $orderId): SalesOrder
    {
        return $this->repository->lazadaOrder($orderId);
    }

    public function negativeStockReport(array $filters): array
    {
        $perPage = (int) ($filters['per_page'] ?? 20);
        $paginated = $this->repository
            ->negativeStockHistoryQuery($filters)
            ->paginate($perPage)
            ->appends($filters);

        $rows = collect($paginated->items())
            ->map(fn ($row) => $this->formatNegativeStockRow($row))
            ->all();

        return [
            'items' => $rows,
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ];
    }

    public function negativeStockRows(array $filters): array
    {
        return $this->repository
            ->negativeStockHistoryQuery($filters)
            ->get()
            ->map(fn ($row) => $this->formatNegativeStockRow($row))
            ->all();
    }

    private function formatNegativeStockRow(object $row): array
    {
        $currentBalance = isset($row->current_balance) ? (float) $row->current_balance : null;
        $normalizedAt = $row->normalized_at ?? null;
        $stillNegative = $currentBalance !== null && $currentBalance < 0;

        return [
            'item_id' => $row->item_id,
            'sku' => $row->sku,
            'product_name' => $row->product_name,
            'location_id' => $row->location_id,
            'location_name' => $row->location_name,
            'bin_id' => $row->bin_id,
            'bin_code' => $row->bin_final_code,
            'first_negative_at' => $row->first_negative_at,
            'last_negative_at' => $row->last_negative_at,
            'min_balance' => (float) $row->min_balance,
            'current_balance' => $currentBalance,
            'normalized_at' => $normalizedAt,
            'triggered_by' => $row->triggered_by,
            'negative_movements_count' => (int) $row->negative_movements_count,
            'still_negative' => $stillNegative,
        ];
    }

    private function normalizeOrderIds($orderIds): ?array
    {
        if (is_string($orderIds)) {
            return array_filter(array_map('trim', explode(',', $orderIds)), fn ($v) => $v !== '');
        }

        if (is_array($orderIds)) {
            return array_filter($orderIds, fn ($v) => $v !== null && $v !== '');
        }

        return null;
    }

    private function wrap($result, string $type): array
    {
        return $result instanceof LengthAwarePaginator
            ? $this->wrapCollection($result, $type)
            : $this->wrapSingle($result, $type);
    }

    private function wrapSingle($model, string $type): array
    {
        return [
            'report_type' => $type,
            'generated_at' => now()->toIso8601String(),
            'data' => $model,
        ];
    }

    private function wrapCollection($paginated, string $type): array
    {
        return [
            'report_type' => $type,
            'generated_at' => now()->toIso8601String(),
            'data' => $paginated->items(),
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ];
    }
}
