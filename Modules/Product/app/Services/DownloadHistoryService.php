<?php

namespace Modules\Product\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Product\Repositories\DownloadHistoryRepository;

class DownloadHistoryService
{
    public function __construct(private DownloadHistoryRepository $repository) {}

    public function paginate(): LengthAwarePaginator
    {
        return $this->repository->paginate();
    }
}
