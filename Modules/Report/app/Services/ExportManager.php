<?php

namespace Modules\Report\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Inventory\Exports\RackAllocationExport;
use Modules\Inventory\Exports\StockAdjustmentExport;
use Modules\Inventory\Services\StockAdjustmentService;
use Modules\Product\Exports\ProductCatalogCsvExport;
use Modules\Purchase\Exports\PurchaseOrderDetailExport;
use Modules\Purchase\Exports\PurchaseOrderListExport;
use Modules\Purchase\Repositories\PurchaseOrderExportRepository;
use Modules\Report\Exports\CustomerListExport;
use Modules\Report\Exports\InventoryStockReportExport;
use Modules\Report\Exports\NegativeStockReportExport;
use Modules\Report\Exports\PicklistDetailExport;
use Modules\Report\Exports\PicklistDetailPhotoExport;
use Modules\Report\Exports\PickListReportExport;
use Modules\Report\Exports\PenyesuaianStokExport;
use Modules\Report\Exports\RincianPendapatanExport;
use Modules\Report\Exports\RincianPendapatanPerBarangExport;
use Modules\Report\Exports\SalesListPesananExport;
use Modules\Report\Exports\SalesProductExport;
use Modules\Report\Exports\SalesReturnExport;
use Modules\Report\Exports\SectionedReportExport;
use Modules\Report\Exports\ShipmentListReportExport;
use Modules\Report\Exports\TransferReportExport;
use Modules\Report\Jobs\RunExportJob;
use Modules\Report\Models\ExportJob;
use Modules\Report\Repositories\ReportRepository;
use Modules\Sales\Exports\CancelledOrdersExport;
use Modules\Sales\Exports\SalesOrdersExport;
use Modules\Sales\Exports\SalesReturnReportExport;
use Modules\Sales\Exports\SettlementReportExport;
use Modules\Sales\Services\OrderSettlementService;

class ExportManager
{
    /** @var array<string, string> PDF export type => query-backed XLSX source type */
    public const TABULAR_PDF_TYPES = [
        'negative-stock-pdf' => 'negative-stock',
        'transfer-pdf' => 'transfer',
        'picklist-list-pdf' => 'picklist-list',
        'shipment-list-pdf' => 'shipment-list',
        'inventory-stock-pdf' => 'inventory-stock',
        'inventory-rack-pdf' => 'inventory-rack',
        'sales-list-pdf' => 'sales-list',
        'sales-product-pdf' => 'sales-product',
        'sales-return-pdf' => 'sales-return',
        'sales-income-pdf' => 'sales-income',
        'customer-list-pdf' => 'customer-list',
        'sales-return-detail-pdf' => 'sales-return-detail',
        'settlement-pdf' => 'settlement',
        'purchase-order-list-pdf' => 'purchase-order-list',
        'purchase-order-detail-pdf' => 'purchase-order-detail',
        'rack-allocation-pdf' => 'rack-allocation',
        'stock-adjustment-pdf' => 'stock-adjustment',
        'stock-adjustment-report-pdf' => 'stock-adjustment-report',
    ];

    public const PDF_TYPES = [
        'monitor-stock-pdf',
        'picklist-pdf',
        'transfer-out-bulk-pdf',
        'putaway-bulk-pdf',
        'stock-adjustment-bulk-pdf',
        'penyesuaian-stok-pdf',
        'picklist-bulk-pdf',
        'manifest-bulk-pdf',
        'invoice-bulk-pdf',
        'order-performance-pdf',
        'putaway-performance-pdf',
        'putaway-list-pdf',
        'shipment-by-courier-pdf',
        'negative-stock-pdf',
        'transfer-pdf',
        'picklist-list-pdf',
        'shipment-list-pdf',
        'inventory-stock-pdf',
        'inventory-rack-pdf',
        'sales-list-pdf',
        'sales-product-pdf',
        'sales-return-pdf',
        'sales-income-pdf',
        'customer-list-pdf',
        'sales-return-detail-pdf',
        'settlement-pdf',
        'purchase-order-list-pdf',
        'purchase-order-detail-pdf',
        'rack-allocation-pdf',
        'stock-adjustment-pdf',
        'stock-adjustment-report-pdf',
    ];

    public const TYPES = [
        'negative-stock',
        'transfer',
        'order-performance',
        'putaway-performance',
        'putaway-list',
        'shipment-by-courier',
        'picklist-detail-photo',
        'picklist-detail',
        'picklist-list',
        'picklist-pdf',
        'inventory-stock',
        'inventory-rack',
        'shipment-list',
        'monitor-stock-xlsx',
        'monitor-stock-pdf',
        'product-catalog-csv',
        'sales-list',
        'sales-product',
        'sales-return',
        'sales-income',
        'customer-list',
        'sales-orders',
        'cancelled-orders',
        'sales-return-detail',
        'settlement',
        'purchase-order-list',
        'purchase-order-detail',
        'rack-allocation',
        'stock-adjustment',
        'stock-adjustment-report',
        'transfer-out-bulk-pdf',
        'putaway-bulk-pdf',
        'stock-adjustment-bulk-pdf',
        'picklist-bulk-pdf',
        'manifest-bulk-pdf',
        'invoice-bulk-pdf',
        'order-performance-pdf',
        'putaway-performance-pdf',
        'putaway-list-pdf',
        'shipment-by-courier-pdf',
        'negative-stock-pdf',
        'transfer-pdf',
        'picklist-list-pdf',
        'shipment-list-pdf',
        'inventory-stock-pdf',
        'inventory-rack-pdf',
        'sales-list-pdf',
        'sales-product-pdf',
        'sales-return-pdf',
        'sales-income-pdf',
        'customer-list-pdf',
        'sales-return-detail-pdf',
        'settlement-pdf',
        'purchase-order-list-pdf',
        'purchase-order-detail-pdf',
        'rack-allocation-pdf',
        'stock-adjustment-pdf',
        'stock-adjustment-report-pdf',
        'penyesuaian-stok-pdf',
    ];

    public function queue(User $user, string $type, array $params): ExportJob
    {
        $this->assertKnown($type);
        $routing = $this->routingFor($type);

        $job = ExportJob::create([
            'user_id' => $user->id,
            'type' => $type,
            'params' => $params,
            'status' => ExportJob::STATUS_QUEUED,
            'queue_connection' => $routing['connection'],
            'queue_name' => $routing['queue'],
        ]);

        RunExportJob::dispatch($job->id, $routing['connection'], $routing['queue'])->afterCommit();

        return $job;
    }

    public function findOwnedOrFail(string $exportId, string $userId): ExportJob
    {
        $job = ExportJob::findOrFail($exportId);
        abort_unless($job->user_id === $userId, 403);

        return $job;
    }

    public function statusPayload(ExportJob $job): array
    {
        $downloadUrl = null;
        if ($job->isReady() && $job->file_path && $job->file_purged_at === null) {
            $downloadUrl = Route::has('api.reports.exports.download')
                ? route('api.reports.exports.download', $job->id)
                : (Route::has('reports.exports.download')
                    ? route('reports.exports.download', $job->id)
                    : url("/api/v1/reports/exports/{$job->id}/download"));
        }

        return [
            'id' => $job->id,
            'type' => $job->type,
            'status' => $job->status,
            'queue' => $job->queue_name,
            'file_name' => $job->file_name,
            'file_available' => $job->file_path !== null && $job->file_purged_at === null,
            'file_purged_at' => $job->file_purged_at?->toIso8601String(),
            'error' => $job->isFailed()
                ? (str_starts_with((string) $job->error, 'PDF dibatasi')
                    ? $job->error
                    : 'Gagal membuat berkas export. Coba lagi atau persempit rentang data.')
                : null,
            'download_url' => $downloadUrl,
        ];
    }

    public function routingFor(string $type): array
    {
        if ($type === 'product-catalog-csv') {
            return [
                'connection' => (string) (config('exports.catalog_connection') ?: config('exports.connection', 'redis-long')),
                'queue' => (string) config('exports.catalog_queue', 'catalog-exports'),
                'profile' => 'catalog',
            ];
        }

        if (in_array($type, self::PDF_TYPES, true)) {
            return [
                'connection' => (string) (config('exports.pdf_connection') ?: config('exports.connection', 'redis-long')),
                'queue' => (string) config('exports.pdf_queue', 'exports-pdf'),
                'profile' => 'pdf',
            ];
        }

        return [
            'connection' => (string) (config('exports.sheet_connection') ?: config('exports.connection', 'redis-long')),
            'queue' => (string) config('exports.sheet_queue', config('exports.queue', 'exports-sheet')),
            'profile' => 'sheet',
        ];
    }

    public function build(string $type, array $params): object
    {
        $this->assertKnown($type);

        return match ($type) {
            'negative-stock' => new NegativeStockReportExport(app(ReportService::class), $params),

            'transfer' => new TransferReportExport(app(ReportService::class), $params),

            'order-performance' => new SectionedReportExport(
                app(OrderPerformanceReportService::class)->sectioned(
                    $params['jenis'],
                    ($params['mode'] ?? null) === 'detail',
                    $params,
                )
            ),

            'putaway-performance' => new SectionedReportExport(
                app(PutawayPerformanceReportService::class)->sectioned(
                    ($params['mode'] ?? null) === 'detail',
                    $params,
                )
            ),

            'putaway-list' => new SectionedReportExport(
                app(PutawayListReportService::class)->sectioned(
                    $params['date'],
                    $params['location_id'],
                    $params['putaway_ids'] ?? [],
                )
            ),

            'shipment-by-courier' => new SectionedReportExport(
                app(ShipmentByCourierReportService::class)->sectioned(
                    ($params['mode'] ?? null) === 'detail',
                    $params,
                )
            ),

            'shipment-list' => new ShipmentListReportExport(
                app(ReportService::class),
                $params,
            ),

            'picklist-detail-photo' => $this->buildPicklistPhoto($params),

            'picklist-detail' => new PicklistDetailExport(
                app(ReportService::class),
                $params,
            ),

            'picklist-list' => new PickListReportExport(
                app(ReportService::class),
                $params,
            ),

            'inventory-stock' => new InventoryStockReportExport(
                app(InventoryStockReportService::class),
                $params,
            ),

            'inventory-rack' => new InventoryStockReportExport(
                app(InventoryStockReportService::class),
                $params,
            ),

            'rack-allocation' => new RackAllocationExport(
                is_string($params['location_id'] ?? null) ? $params['location_id'] : null,
                is_string($params['search'] ?? null) ? $params['search'] : null,
            ),

            'stock-adjustment' => new StockAdjustmentExport(
                app(StockAdjustmentService::class)->getQueryForExport(
                    Request::create('/', 'GET', [
                        'search' => $params['search'] ?? null,
                        'filter' => [
                            'location_id' => $params['location_id'] ?? null,
                            'date_from' => $params['date_from'] ?? null,
                            'date_to' => $params['date_to'] ?? null,
                        ],
                    ]),
                ),
            ),

            'stock-adjustment-report' => new PenyesuaianStokExport(
                app(ReportRepository::class)->penyesuaianStokLinesQuery(
                    (string) $params['start_date'],
                    (string) $params['end_date'],
                    (array) ($params['product_ids'] ?? []),
                    (array) ($params['location_ids'] ?? []),
                ),
            ),

            'monitor-stock-xlsx' => app(MonitorStockReportService::class)->export($params),

            'monitor-stock-pdf' => throw new \LogicException('PDF Monitor Stok diproses langsung oleh worker.'),

            'product-catalog-csv' => new ProductCatalogCsvExport($params),

            'sales-list' => new SalesListPesananExport(
                app(SalesListReportService::class)->query($params),
            ),

            'sales-product' => new SalesProductExport(
                app(SalesProductReportService::class)->query($params),
            ),

            'sales-return' => new SalesReturnExport(
                app(SalesReturnReportService::class)->query($params),
            ),

            'sales-income' => $this->buildSalesIncome($params),

            'customer-list' => new CustomerListExport(
                app(CustomerListReportService::class)->query($params),
            ),

            'sales-orders' => new SalesOrdersExport(
                tab: $params['tab'] ?? null,
                dateFrom: $params['date_from'] ?? null,
                dateTo: $params['date_to'] ?? null,
                source: $params['source'] ?? null,
                search: $params['search'] ?? null,
                storeId: $params['store_id'] ?? null,
                locationId: $params['location_id'] ?? null,
            ),

            'cancelled-orders' => new CancelledOrdersExport(
                dateFrom: $params['date_from'] ?? null,
                dateTo: $params['date_to'] ?? null,
                postPackOnly: (bool) ($params['post_pack_only'] ?? false),
                source: $params['source'] ?? null,
            ),

            'sales-return-detail' => new SalesReturnReportExport(
                dateFrom: $params['date_from'] ?? null,
                dateTo: $params['date_to'] ?? null,
                locationId: $params['location_id'] ?? null,
                channelShopId: $params['channel_shop_id'] ?? null,
                status: $params['status'] ?? null,
                source: $params['source'] ?? null,
                reasonCategory: $params['reason_category'] ?? null,
                marketplaceDecision: $params['marketplace_decision'] ?? null,
            ),

            'settlement' => new SettlementReportExport(
                app(OrderSettlementService::class)
                    ->queryForExport($params),
            ),

            'purchase-order-list' => new PurchaseOrderListExport(
                app(PurchaseOrderExportRepository::class)
                    ->getListQuery($params),
            ),

            'purchase-order-detail' => new PurchaseOrderDetailExport(
                app(PurchaseOrderExportRepository::class)
                    ->getDetailQuery($params),
            ),

            'putaway-bulk-pdf', 'stock-adjustment-bulk-pdf', 'picklist-bulk-pdf',
            'manifest-bulk-pdf', 'invoice-bulk-pdf' => throw new \LogicException('PDF bulk diproses langsung oleh worker.'),
        };
    }

    public function isTabularPdf(string $type): bool
    {
        return array_key_exists($type, self::TABULAR_PDF_TYPES);
    }

    public function sourceTypeForTabularPdf(string $type): string
    {
        if (! $this->isTabularPdf($type)) {
            throw new \InvalidArgumentException("Tipe PDF tabel tidak dikenal: {$type}");
        }

        return self::TABULAR_PDF_TYPES[$type];
    }

    public function labelFor(string $type): string
    {
        return match ($type) {
            'negative-stock' => 'Riwayat Stok Minus',
            'transfer' => 'Laporan Transfer Gudang',
            'picklist-list' => 'Daftar Picklist',
            'shipment-list' => 'Daftar Pengiriman',
            'inventory-stock' => 'Laporan Persediaan Barang',
            'inventory-rack' => 'Laporan Persediaan per Rak',
            'sales-list' => 'Daftar Penjualan',
            'sales-product' => 'Laporan Penjualan Produk',
            'sales-return' => 'Laporan Retur Penjualan',
            'sales-income' => 'Rincian Pendapatan',
            'customer-list' => 'Daftar Pelanggan',
            'sales-return-detail' => 'Rincian Retur',
            'settlement' => 'Laporan Settlement',
            'purchase-order-list' => 'Daftar Pesanan Pembelian',
            'purchase-order-detail' => 'Rincian Pesanan Pembelian',
            'rack-allocation' => 'Alokasi Rak',
            'stock-adjustment' => 'Koreksi Stok',
            default => 'Laporan',
        };
    }

    public function filename(string $type, array $params): string
    {
        if ($this->isTabularPdf($type)) {
            $sourceFilename = $this->filename($this->sourceTypeForTabularPdf($type), $params);

            return (string) preg_replace('/\.(xlsx|csv)$/i', '.pdf', $sourceFilename);
        }

        return match ($type) {
            'negative-stock' => sprintf(
                'riwayat-stok-minus_%s_%s.xlsx',
                $params['from'] ?? 'semua',
                $params['to'] ?? now()->format('Y-m-d'),
            ),

            'transfer' => sprintf(
                'laporan-transfer-%s_%s_%s.xlsx',
                $params['jenis'] ?? 'masuk',
                $params['from'] ?? '',
                $params['to'] ?? '',
            ),

            'order-performance' => sprintf(
                'Laporan-Performa-%s-%s_%s_%s.xlsx',
                ucfirst($params['jenis'] ?? ''),
                ucfirst($params['mode'] ?? ''),
                $params['from'] ?? '',
                $params['to'] ?? '',
            ),

            'order-performance-pdf' => sprintf(
                'Laporan-Performa-%s-%s_%s_%s.pdf',
                ucfirst($params['jenis'] ?? ''), ucfirst($params['mode'] ?? ''), $params['from'] ?? '', $params['to'] ?? '',
            ),

            'putaway-performance' => sprintf(
                'Laporan-Performa-Penempatan-%s_%s_%s.xlsx',
                ucfirst($params['mode'] ?? ''),
                $params['from'] ?? '',
                $params['to'] ?? '',
            ),

            'putaway-performance-pdf' => sprintf(
                'Laporan-Performa-Penempatan-%s_%s_%s.pdf',
                ucfirst($params['mode'] ?? ''), $params['from'] ?? '', $params['to'] ?? '',
            ),

            'putaway-list' => sprintf(
                'Daftar-Penempatan-Barang_%s.xlsx',
                $params['date'] ?? now()->format('Y-m-d'),
            ),

            'putaway-list-pdf' => sprintf('Daftar-Penempatan-Barang_%s.pdf', $params['date'] ?? now()->format('Y-m-d')),

            'shipment-by-courier' => sprintf(
                'Laporan-Pengiriman-Ekspedisi-%s_%s_%s.xlsx',
                ucfirst($params['mode'] ?? ''),
                $params['from'] ?? '',
                $params['to'] ?? '',
            ),

            'shipment-by-courier-pdf' => sprintf(
                'Laporan-Pengiriman-Ekspedisi-%s_%s_%s.pdf',
                ucfirst($params['mode'] ?? ''), $params['from'] ?? '', $params['to'] ?? '',
            ),

            'shipment-list' => sprintf(
                'daftar-pengiriman_%s_%s.xlsx',
                $params['from'] ?? '',
                $params['to'] ?? '',
            ),

            'picklist-detail-photo' => sprintf(
                'Detail-Picklist_%s.xlsx',
                substr((string) ($params['picklist_id'] ?? 'export'), 0, 8),
            ),

            'picklist-list' => sprintf(
                'daftar-picklist_%s_%s.xlsx',
                $params['from'] ?? 'semua',
                $params['to'] ?? now()->format('Y-m-d'),
            ),

            'picklist-detail' => sprintf(
                'Detail-Picklist_%s.xlsx',
                substr((string) ($params['picklist_id'] ?? 'export'), 0, 8),
            ),

            'picklist-pdf' => sprintf(
                '%s.pdf',
                $this->safeFilenameStem(
                    (string) ($params['picklist_no'] ?? '')
                    ?: 'Picklist_'.substr((string) ($params['picklist_id'] ?? 'export'), 0, 8),
                ),
            ),

            'inventory-stock' => sprintf(
                'persediaan-barang-%s.xlsx',
                ($params['report_type'] ?? 'per-lokasi') === 'as_of_date'
                    ? 'per-tanggal-'.($params['as_of_date'] ?? now()->toDateString())
                    : 'per-lokasi',
            ),

            'inventory-rack' => sprintf(
                'persediaan-per-rak-%s.xlsx',
                now()->format('Y-m-d'),
            ),

            'monitor-stock-xlsx' => 'monitor-stok-'.($params['tab'] ?? 'export').'-'.now()->format('Y-m-d_His').'.xlsx',

            'monitor-stock-pdf' => 'monitor-stok-'.($params['tab'] ?? 'export').'-'.now()->format('Y-m-d_His').'.pdf',

            'product-catalog-csv' => 'katalog-produk-'.now()->format('Y-m-d_His').'.csv',

            'sales-list' => sprintf('Daftar-Penjualan_%s_%s.xlsx', $params['from'] ?? 'semua', $params['to'] ?? now()->format('Y-m-d')),
            'sales-product' => sprintf('Daftar-Penjualan-Produk_%s_%s.xlsx', $params['from'] ?? 'semua', $params['to'] ?? now()->format('Y-m-d')),
            'sales-return' => sprintf('Daftar-Retur-Penjualan_%s_%s.xlsx', $params['from'] ?? 'semua', $params['to'] ?? now()->format('Y-m-d')),
            'sales-income' => sprintf('Rincian-Pendapatan_%s_%s.xlsx', $params['from'] ?? 'semua', $params['to'] ?? now()->format('Y-m-d')),
            'customer-list' => sprintf('Daftar-Pelanggan_%s_%s.xlsx', $params['from'] ?? 'semua', $params['to'] ?? now()->format('Y-m-d')),
            'sales-orders' => 'pesanan-'.($params['tab'] ?? 'semua').'-'.now()->format('Ymd-His').'.xlsx',
            'cancelled-orders' => 'cancel-orders-'.($params['date_from'] ?? 'all').'-'.($params['date_to'] ?? now()->format('Y-m-d')).'.xlsx',
            'sales-return-detail' => sprintf(
                'laporan-retur-%s-%s.xlsx',
                $params['date_from'] ?? 'semua',
                $params['date_to'] ?? now()->format('Y-m-d'),
            ),
            'settlement' => 'laporan-settlement-'.now()->format('Ymd-His').'.xlsx',
            'purchase-order-list' => 'purchase-orders-list-'.now()->format('Y-m-d-His').'.csv',
            'purchase-order-detail' => 'purchase-orders-details-'.now()->format('Y-m-d-His').'.csv',
            'rack-allocation' => 'alokasi-rak-'.now()->format('Ymd-His').'.xlsx',
            'stock-adjustment' => 'koreksi-stok-'.now()->format('Ymd').'.xlsx',
            'stock-adjustment-report' => sprintf(
                'Daftar-Penyesuaian-Stok_%s_%s.xlsx',
                $params['start_date'] ?? 'semua', $params['end_date'] ?? now()->format('Y-m-d'),
            ),

            'penyesuaian-stok-pdf' => sprintf(
                'Daftar-Penyesuaian-Stok_%s_%s.pdf',
                $params['start_date'] ?? 'semua', $params['end_date'] ?? now()->format('Y-m-d'),
            ),

            'transfer-out-bulk-pdf' => 'Surat-Jalan-Bulk-'.now()->format('Y-m-d_His').'.pdf',

            'putaway-bulk-pdf' => 'Putaway-Bulk-'.now()->format('Y-m-d_His').'.pdf',

            'stock-adjustment-bulk-pdf' => 'Laporan-Penyesuaian-Bulk-'.now()->format('Y-m-d_His').'.pdf',

            'picklist-bulk-pdf' => 'Picklist-Bulk-'.now()->format('Y-m-d_His').'.pdf',

            'manifest-bulk-pdf' => 'Manifest-Bulk-'.now()->format('Y-m-d_His').'.pdf',

            'invoice-bulk-pdf' => 'Faktur-Bulk-'.now()->format('Y-m-d_His').'.pdf',

            default => 'export.xlsx',
        };
    }

    private function buildPicklistPhoto(array $params): PicklistDetailPhotoExport
    {
        $data = app(ReportService::class)->pickListDetailData(
            $params['picklist_id'],
            $params['order_ids'] ?? null,
        );

        return new PicklistDetailPhotoExport($data['picklist'], $data['groups']);
    }

    private function buildSalesIncome(array $params): RincianPendapatanExport|RincianPendapatanPerBarangExport
    {
        $mode = $params['jenis'] ?? RincianPendapatanReportService::MODE_RINCIAN;
        $query = app(RincianPendapatanReportService::class)->query($mode, $params);

        return $mode === RincianPendapatanReportService::MODE_PER_BARANG
            ? new RincianPendapatanPerBarangExport($query)
            : new RincianPendapatanExport($query);
    }

    private function assertKnown(string $type): void
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Tipe export tidak dikenal: {$type}");
        }
    }

    private function safeFilenameStem(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($value));

        return $safe !== null && $safe !== '' ? $safe : 'export';
    }
}
