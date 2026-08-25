<?php

namespace Modules\Report\Services;

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Modules\Report\Exports\InventoryStockReportExport;
use Modules\Report\Exports\NegativeStockReportExport;
use Modules\Report\Exports\PicklistDetailPhotoExport;
use Modules\Report\Exports\SectionedReportExport;
use Modules\Report\Exports\TransferReportExport;
use Modules\Report\Jobs\RunExportJob;
use Modules\Report\Models\ExportJob;

class ExportManager
{
    public const TYPES = [
        'negative-stock',
        'transfer',
        'order-performance',
        'putaway-performance',
        'putaway-list',
        'shipment-by-courier',
        'picklist-detail-photo',
        'inventory-stock',
        'inventory-rack',
    ];

    public function queue(User $user, string $type, array $params): ExportJob
    {
        $this->assertKnown($type);

        $job = ExportJob::create([
            'user_id' => $user->id,
            'type' => $type,
            'params' => $params,
            'status' => ExportJob::STATUS_QUEUED,
        ]);

        RunExportJob::dispatch($job->id);

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
        if ($job->isReady()) {
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
            'file_name' => $job->file_name,
            'error' => $job->isFailed()
                ? 'Gagal membuat berkas export. Coba lagi atau persempit rentang data.'
                : null,
            'download_url' => $downloadUrl,
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

            'picklist-detail-photo' => $this->buildPicklistPhoto($params),

            'inventory-stock' => new InventoryStockReportExport(
                app(InventoryStockReportService::class),
                $params,
            ),

            'inventory-rack' => new InventoryStockReportExport(
                app(InventoryStockReportService::class),
                $params,
            ),
        };
    }

    public function filename(string $type, array $params): string
    {
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

            'putaway-performance' => sprintf(
                'Laporan-Performa-Penempatan-%s_%s_%s.xlsx',
                ucfirst($params['mode'] ?? ''),
                $params['from'] ?? '',
                $params['to'] ?? '',
            ),

            'putaway-list' => sprintf(
                'Daftar-Penempatan-Barang_%s.xlsx',
                $params['date'] ?? now()->format('Y-m-d'),
            ),

            'shipment-by-courier' => sprintf(
                'Laporan-Pengiriman-Ekspedisi-%s_%s_%s.xlsx',
                ucfirst($params['mode'] ?? ''),
                $params['from'] ?? '',
                $params['to'] ?? '',
            ),

            'picklist-detail-photo' => sprintf(
                'Detail-Picklist_%s.xlsx',
                substr((string) ($params['picklist_id'] ?? 'export'), 0, 8),
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

    private function assertKnown(string $type): void
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Tipe export tidak dikenal: {$type}");
        }
    }
}
