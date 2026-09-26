<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Modules\Inventory\DTO\OrderAuditReportResult;
use Modules\Inventory\Repositories\OrderAuditReportRepository;

final readonly class OrderAuditReportService
{
    public function __construct(
        private OrderAuditReportRepository $repository,
    ) {}

    public function paginate(): OrderAuditReportResult
    {
        return $this->repository->paginate();
    }
}
