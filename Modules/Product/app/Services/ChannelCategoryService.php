<?php

namespace Modules\Product\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Product\Repositories\ChannelCategoryRepository;

class ChannelCategoryService
{
    protected ChannelCategoryRepository $repository;

    public function __construct(ChannelCategoryRepository $repository)
    {
        $this->repository = $repository;
    }

    public function getPaginated(string $channelId, int $perPage = 10): LengthAwarePaginator
    {
        return $this->repository->getPaginated($channelId, $perPage);
    }

    public function getAll(string $channelId): Collection
    {
        return $this->repository->getAll($channelId);
    }
}
