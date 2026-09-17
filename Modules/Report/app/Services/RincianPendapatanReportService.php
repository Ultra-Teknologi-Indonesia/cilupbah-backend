<?php

namespace Modules\Report\Services;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Modules\Report\Repositories\ReportRepository;

class RincianPendapatanReportService
{
    public const MODE_RINCIAN = 'rincian';
    public const MODE_PER_BARANG = 'per_barang';

    public function __construct(
        protected ReportRepository $repository,
    ) {}

    public function query(string $mode, array $filters): EloquentBuilder|QueryBuilder
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(300);

        return $mode === self::MODE_PER_BARANG
            ? $this->repository->rincianPendapatanPerBarangQuery($filters)
            : $this->repository->rincianPendapatanQuery($filters);
    }
}
