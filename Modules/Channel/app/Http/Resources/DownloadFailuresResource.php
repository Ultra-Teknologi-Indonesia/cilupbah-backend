<?php

namespace Modules\Channel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Channel\Support\DownloadFailureReport;

class DownloadFailuresResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var DownloadFailureReport $report */
        $report = $this->resource;
        $transaction = $report->transaction;

        return [
            'trx_id' => $transaction->id,
            'trx_no' => $transaction->trx_no,
            'state' => $transaction->state,
            'total_failed' => (int) $transaction->total_failed,
            'logged_failures' => $report->loggedFailures,
            'job_error' => $report->jobError,
            'reasons' => $report->reasons,
            'samples' => $report->samples,
        ];
    }
}
