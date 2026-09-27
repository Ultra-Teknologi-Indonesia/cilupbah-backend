<?php

declare(strict_types=1);

namespace Modules\Channel\Services;

use Modules\Channel\Repositories\ChannelStockSyncOutboxRepository;

final class ChannelStockSyncOutboxMonitorService
{
    public function __construct(
        private readonly ChannelStockSyncOutboxRepository $repository,
    ) {}

    public function snapshot(): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'summary' => $this->repository->monitoringSummary(),
            'errors' => $this->repository->monitoringErrors(20),
        ];
    }
}
