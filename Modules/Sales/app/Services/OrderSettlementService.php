<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;
use Modules\Sales\Repositories\OrderSettlementRepository;

final class OrderSettlementService
{
    public function __construct(
        private readonly OrderSettlementRepository $repository,
    ) {}

    public function paginate(): LengthAwarePaginator
    {
        return $this->repository->getPaginated();
    }

    public function summary(): array
    {
        return $this->repository->summary();
    }

    public function allForExport(): Collection
    {
        return $this->repository->query()->get();
    }

    public function queryForExport(array $filters = []): Builder
    {
        return $this->repository->exportQuery($filters);
    }
}
