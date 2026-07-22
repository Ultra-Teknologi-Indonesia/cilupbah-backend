<?php

namespace Modules\Report\Services;

use Illuminate\Database\Eloquent\Builder;
use Modules\Report\Repositories\ReportRepository;

class SalesListReportService
{
    public function __construct(
        protected ReportRepository $repository,
    ) {}

    public function query(array $filters): Builder
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(300);

        return $this->repository->salesListQuery($filters);
    }
}
