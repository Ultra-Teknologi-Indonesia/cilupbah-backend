<?php

namespace Modules\Channel\Support;

use Modules\Channel\Models\DownloadTransaction;

final class DownloadFailureReport
{
    public function __construct(
        public readonly DownloadTransaction $transaction,
        public readonly int $loggedFailures,
        public readonly array $reasons,
        public readonly array $samples,
        public readonly ?array $jobError,
    ) {}
}
