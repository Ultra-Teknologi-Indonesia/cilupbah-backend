<?php

declare(strict_types=1);

namespace Modules\Inventory\DTO;

use Illuminate\Pagination\LengthAwarePaginator;

final readonly class OrderAuditReportResult
{
    public function __construct(
        public OrderAuditReportSummary $summary,
        public LengthAwarePaginator $paginator,
    ) {}
}
