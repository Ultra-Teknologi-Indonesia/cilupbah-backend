<?php

namespace Modules\Product\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Product\Models\Product;
use Modules\Product\Repositories\MasterFeedRepository;

/**
 * Pembacaan katalog: item per grup (master_name) & detail produk untuk persiapan listing.
 */
class CatalogService
{
    public function __construct(
        private readonly MasterFeedRepository $repository,
    ) {}

    public function itemsByGroup(string $groupId): LengthAwarePaginator
    {
        return $this->repository->paginateByMasterName($groupId);
    }

    public function forListing(string $id): ?Product
    {
        return $this->repository->findForListing($id);
    }
}
