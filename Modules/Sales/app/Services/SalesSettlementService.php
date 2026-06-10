<?php

namespace Modules\Sales\Services;

use Modules\Sales\Models\SalesSettlement;
use Modules\Sales\Repositories\SalesSettlementRepository;

class SalesSettlementService
{
    public function __construct(
        protected SalesSettlementRepository $repo,
    ) {}

    public function getAllPaginated(int $limit = 10)
    {
        return $this->repo->getAllPaginated($limit);
    }

    public function getById(string $id): ?SalesSettlement
    {
        return $this->repo->findById($id);
    }
}
