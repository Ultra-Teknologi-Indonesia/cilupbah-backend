<?php

declare(strict_types=1);

namespace Modules\Report\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;
use Modules\Report\Models\ExportJob;
use Modules\Report\Support\ExportCatalog;

final class ExportJobResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $job = $this->resource;
        $status = $job->effectiveStatus();
        $available = $job->isReady()
            && $job->file_path !== null
            && $job->file_purged_at === null;
        $expiresAt = ($job->isReady() || $job->file_purged_at !== null)
            ? $job->finished_at?->copy()->addHours(
                max(1, (int) config('file-retention.export_hours', 168)),
            )
            : null;
        $downloadUrl = null;
        if ($available) {
            $downloadUrl = Route::has('api.reports.exports.download')
                ? route('api.reports.exports.download', $job->id)
                : (Route::has('reports.exports.download')
                    ? route('reports.exports.download', $job->id)
                    : url("/api/v1/reports/exports/{$job->id}/download"));
        }

        return [
            'id' => (string) $job->id,
            'type' => $job->type,
            'label' => ExportCatalog::label($job->type),
            'category' => ExportCatalog::category($job->type),
            'format' => ExportCatalog::format($job->type),
            'filter_summary' => $this->filterSummary($job),
            'status' => $status,
            'file_name' => $job->file_name,
            'file_size' => $job->file_size,
            'created_at' => $job->created_at?->toIso8601String(),
            'started_at' => $job->started_at?->toIso8601String(),
            'finished_at' => $job->finished_at?->toIso8601String(),
            'file_available' => $available,
            'file_purged_at' => $job->file_purged_at?->toIso8601String(),
            'expires_at' => $expiresAt?->toIso8601String(),
            'error' => $job->isFailed()
                ? (str_starts_with((string) $job->error, 'PDF dibatasi')
                    ? $job->error
                    : 'Gagal membuat berkas export. Coba lagi atau persempit rentang data.')
                : null,
            'download_url' => $downloadUrl,
        ];
    }

    private function filterSummary(ExportJob $job): ?string
    {
        $params = is_array($job->params) ? $job->params : [];
        $parts = [];

        $from = $this->firstScalar($params, ['from', 'date_from', 'start_date']);
        $to = $this->firstScalar($params, ['to', 'date_to', 'end_date', 'as_of_date']);
        if ($from !== null || $to !== null) {
            $parts[] = 'Periode: '.($from ?? 'awal').' s/d '.($to ?? 'sekarang');
        }

        foreach (['jenis' => 'Jenis', 'mode' => 'Mode', 'tab' => 'Tab', 'report_type' => 'Tipe'] as $key => $label) {
            $value = $this->firstScalar($params, [$key]);
            if ($value !== null) {
                $parts[] = $label.': '.$value;
            }
        }

        return $parts === [] ? null : implode(' • ', $parts);
    }

    private function firstScalar(array $params, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $params[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return mb_substr(trim((string) $value), 0, 100);
            }
        }

        return null;
    }
}
