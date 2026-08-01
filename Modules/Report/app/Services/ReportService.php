<?php

namespace Modules\Report\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Modules\Report\Repositories\ReportRepository;
use Modules\Sales\Enums\ChannelStatus;
use Modules\Sales\Support\ChannelStatusNormalizer;
use Modules\Sales\Models\SalesOrder;

class ReportService
{

    public const ORDER_STATUS_LABELS = [
        'pending'                    => 'Menunggu',
        'unpaid'                     => 'Belum Dibayar',
        'paid'                       => 'Dibayar',
        'reserved'                   => 'Siap Proses',
        'processing'                 => 'Diproses',
        'picked'                     => 'Dipick',
        'packed'                     => 'Dikemas - Siap Dikirim',
        'ready'                      => 'Siap Kirim',
        'ready to ship'              => 'Siap Kirim',
        'awaiting buyer confirmation' => 'Menunggu Konfirmasi Pembeli',
        'shipped'                    => 'Dikirim',
        'completed'                  => 'Selesai',
        'cancelled'                  => 'Dibatalkan',
        'canceled'                   => 'Dibatalkan',
        'request cancel'             => 'Permintaan Batal',
        'returned'                   => 'Diretur',
    ];

    public const CHANNEL_STATUS_LABELS = [
        'UNPAID'             => 'Belum Dibayar',
        'READY_TO_SHIP'      => 'Siap Kirim',
        'PROCESSED'          => 'Diproses',
        'SHIPPED'            => 'Dikirim',
        'TO_CONFIRM_RECEIVE' => 'Menunggu Konfirmasi Terima',
        'COMPLETED'          => 'Selesai',
        'CANCELLED'          => 'Dibatalkan',
        'RETURN_REQUESTED'   => 'Pengajuan Retur',
        'RETURNED'           => 'Diretur',
        'IN_CANCEL'          => 'Proses Pembatalan',
        'UNKNOWN'            => 'Tidak Diketahui',
    ];

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
        $paper = $data['paper'] ?? 'thermal_50x40';

        $variants = $this->repository->barcodeVariants($jenis, $ids);

        $onlineMappings = collect();
        if ($harga === 'online' && $variants->isNotEmpty()) {
            $onlineMappings = $this->repository->barcodeOnlineMappings($variants->pluck('id'));
        }

        $homeBins = $variants->isNotEmpty()
            ? $this->repository->barcodeKecilHomeBins($variants->pluck('id'))
            : [];

        $cells = [];
        $qrCache = [];

        foreach ($variants as $variant) {
            $sku = (string) $variant->sku;
            if ($sku === '') {
                continue;
            }
            $bin = $homeBins[$variant->id] ?? null;

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
                        'bin' => $bin,
                        'qr' => $qr,
                        'store_name' => $shop?->shop_name,
                        'price' => $price !== null ? (float) $price : null,
                    ];
                }
            } else {
                $cells[] = [
                    'sku' => $sku,
                    'bin' => $bin,
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
            'paper' => $paper,
        ]);

        switch ($paper) {
            case 'thermal_50x40':
                $pdf->setPaper([0, 0, 141.7, 113.4], 'portrait');
                break;
            case 'thermal_80x40':
                $pdf->setPaper([0, 0, 226.8, 113.4], 'portrait');
                break;
            case 'thermal_40x30':
                $pdf->setPaper([0, 0, 113.4, 85.0], 'portrait');
                break;
            case 'thermal_30x20':
                $pdf->setPaper([0, 0, 85.0, 56.7], 'portrait');
                break;
            default:
                $pdf->setPaper('a4', 'portrait');
                break;
        }

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
        $payload = $this->penyesuaianStokPayload($data);

        $pdf = Pdf::loadView('report::pdf.penyesuaian-stok', $payload);
        $pdf->setPaper('a4', 'portrait');

        return $pdf;
    }

    public function penyesuaianStokPayload(array $data): array
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

        return [
            'groups' => $groups,
            'start' => $startDate,
            'end' => $endDate,
        ];
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

    public function pickListRowsQuery(array $filters): \Illuminate\Database\Query\Builder
    {
        return $this->repository->pickListRowsQuery($filters);
    }

    public function shipmentListQuery(array $filters): \Illuminate\Database\Query\Builder
    {
        return $this->repository->shipmentListQuery($filters);
    }

    public function shipmentFilterOptions(): array
    {
        return [
            'couriers' => $this->repository->courierOptions()
                ->map(fn ($c) => ['value' => $c->id, 'label' => $c->name])
                ->all(),
            'statuses' => $this->statusMpOptions(),
        ];
    }

    private const CHANNEL_LABELS = [
        'shopee'      => 'Shopee',
        'tiktok'      => 'TikTok',
        'lazada'      => 'Lazada',
        'woocommerce' => 'WooCommerce',
    ];

    private const STATUS_LIFECYCLE = [
        'UNPAID',
        'READY_TO_SHIP',
        'PROCESSED',
        'SHIPPED',
        'TO_CONFIRM_RECEIVE',
        'COMPLETED',
        'IN_CANCEL',
        'CANCELLED',
        'RETURN_REQUESTED',
        'RETURNED',
        'UNKNOWN',
    ];

    private function statusMpOptions(): array
    {
        $lifecycle = array_flip(self::STATUS_LIFECYCLE);
        $options = [];

        foreach (ChannelStatusNormalizer::catalog() as $channel => $statuses) {
            foreach (array_unique(array_map(fn ($s) => $s->value, $statuses)) as $canonical) {
                $options[] = [
                    'value' => $channel . '::' . $canonical,
                    'label' => self::statusMpLabel($channel, $canonical),
                    'sort' => [$lifecycle[$canonical] ?? 99, self::CHANNEL_LABELS[$channel] ?? $channel],
                ];
            }
        }

        usort($options, fn ($a, $b) => $a['sort'] <=> $b['sort']);

        return array_map(
            fn ($o) => ['value' => $o['value'], 'label' => $o['label']],
            $options,
        );
    }

    public static function statusMpLabel(?string $source, string $canonical): string
    {
        $status = self::CHANNEL_STATUS_LABELS[$canonical]
            ?? ucwords(strtolower(str_replace('_', ' ', $canonical)));

        if (! $source) {
            return $status;
        }

        return $status . ' — ' . (self::CHANNEL_LABELS[$source] ?? ucfirst($source));
    }

    public static function channelStatusLabel(?string $channelStatus): ?string
    {
        if ($channelStatus === null || $channelStatus === '') {
            return null;
        }

        return self::CHANNEL_STATUS_LABELS[$channelStatus] ?? $channelStatus;
    }

    public static function orderStatusLabel(?string $status): ?string
    {
        if ($status === null || $status === '') {
            return null;
        }

        $key = str_replace(['-', '_'], ' ', strtolower(trim($status)));

        return self::ORDER_STATUS_LABELS[$key] ?? $status;
    }

    public function pickListLookup(?string $search, int $perPage = 20): array
    {
        return $this->repository->pickListLookup($search, $perPage)
            ->map(fn ($p) => [
                'value' => $p->id,
                'label' => $p->picklist_no,
                'orders' => $p->items
                    ->map(fn ($i) => [
                        'value' => $i->order_id,
                        'label' => $i->order?->salesorder_no ?? '-',
                    ])
                    ->unique('value')
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    public function pickListDetailBuild(string $picklistId, ?array $orderIds = null)
    {
        $data = $this->pickListDetailPayload($picklistId, $orderIds);

        $pdf = Pdf::loadView('report::pdf.detail-picklist', $data);
        $pdf->setPaper('a4', 'portrait');

        return $pdf;
    }

    public function pickListDetailPayload(string $picklistId, ?array $orderIds = null): array
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(180);

        return $this->pickListDetailData($picklistId, $orderIds);
    }

    public function pickListDetailData(string $picklistId, ?array $orderIds = null): array
    {
        $picklist = $this->repository->pickList([
            'picklist_id' => $picklistId,
            'order_ids' => $this->normalizeOrderIds($orderIds),
        ]);

        $items = collect($picklist->items);
        if (! empty($orderIds)) {
            $items = $items->filter(fn ($i) => in_array($i->order_id, $orderIds, true));
        }

        $groups = $items
            ->groupBy('order_id')
            ->map(fn ($rows) => [
                'order_no' => $rows->first()->order?->salesorder_no ?? '-',
                'rows' => $rows->map(fn ($i) => [
                    'image_url' => $i->image_url,
                    'sku' => $i->sku,
                    'product_name' => $i->product?->product?->name,
                    'qty_ordered' => (int) $i->qty_ordered,
                    'qty_picked' => (int) $i->qty_picked,
                    'location_name' => $picklist->location?->location_name,
                    'bin_code' => $i->bin?->bin_final_code,
                ])->values()->all(),
            ])
            ->values();

        return ['picklist' => $picklist, 'groups' => $groups];
    }

    public function transferReportRows(array $filters): array
    {
        $isMasuk = ($filters['jenis'] ?? 'keluar') === 'masuk';

        return $this->repository
            ->transferQuery($filters)
            ->get()
            ->map(fn ($row) => $this->formatTransferRow($row, $isMasuk))
            ->all();
    }

    private function formatTransferRow(object $row, bool $isMasuk): array
    {
        $catatan = $row->item_notes ?: ($row->transfer_notes ?: null);

        $common = [
            'tanggal' => $row->tanggal,
            'lokasi_asal' => $row->location_source,
            'lokasi_tujuan' => $row->location_destination,
            'sku' => $row->sku,
            'nama_barang' => $row->product_name,
            'qty' => (float) $row->qty,
            'catatan' => $catatan,
        ];

        if (! $isMasuk) {
            return ['no_transfer' => $row->transfer_number] + $common;
        }

        return [
            'no_terima' => $row->receive_number,
            'tanggal_terima' => $row->received_at,
            'no_transfer_asal' => $row->transfer_number,
        ] + $common;
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
