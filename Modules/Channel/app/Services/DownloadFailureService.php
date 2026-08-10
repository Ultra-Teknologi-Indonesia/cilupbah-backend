<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Str;
use Modules\Channel\Models\DownloadTransaction;
use Modules\Channel\Repositories\DownloadTransactionRepository;
use Modules\Channel\Support\DownloadFailureClassifier;
use Modules\Channel\Support\DownloadFailureReport;
use Modules\Channel\Support\UploadErrorPresenter;

class DownloadFailureService
{
    public function __construct(
        private DownloadTransactionRepository $repository,
    ) {}

    public function report(DownloadTransaction $transaction): DownloadFailureReport
    {
        $logs = $this->repository->failureLogs($transaction);

        $grouped = [];
        $samples = [];

        foreach ($logs as $log) {
            $reason = DownloadFailureClassifier::classify($log->error_message);
            $grouped[$reason] = ($grouped[$reason] ?? 0) + 1;

            if (count($samples) < 20) {
                $samples[] = [
                    'external_product_id' => data_get($log->payload, 'external_product_id'),
                    'title' => data_get($log->payload, 'title'),
                    'reason' => $reason,
                    'detail' => Str::limit((string) $log->error_message, 300),
                ];
            }
        }

        arsort($grouped);

        $reasons = array_map(
            fn ($reason, $count) => ['reason' => $reason, 'count' => $count],
            array_keys($grouped),
            array_values($grouped),
        );

        return new DownloadFailureReport(
            transaction: $transaction,
            loggedFailures: $logs->count(),
            reasons: $reasons,
            samples: $samples,
            jobError: $this->jobError($transaction),
        );
    }

    private function jobError(DownloadTransaction $transaction): ?array
    {
        if ($transaction->state !== DownloadTransaction::STATE_FAILED || empty($transaction->error_message)) {
            return null;
        }

        $channelCode = $transaction->channelShop?->channel?->code ?? '';

        return UploadErrorPresenter::fromMessage($channelCode, (string) $transaction->error_message);
    }
}
