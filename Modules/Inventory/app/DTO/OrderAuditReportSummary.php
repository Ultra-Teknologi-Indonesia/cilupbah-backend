<?php

declare(strict_types=1);

namespace Modules\Inventory\DTO;

final readonly class OrderAuditReportSummary
{
    public function __construct(
        public int $marketplaceTotal,
        public int $wmsTotal,
        public int $matchedTotal,
        public int $missingTotal,
        public int $statusMismatchTotal,
        public ?string $lastSyncAt,
        public string $lastCheckedAt,
    ) {}
}
