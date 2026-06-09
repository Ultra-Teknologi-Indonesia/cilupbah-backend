<?php

namespace Modules\Product\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Product\Repositories\ReviewFeedRepository;

class ReviewFeedService
{
    public function __construct(private ReviewFeedRepository $repository) {}

    public function paginate(): LengthAwarePaginator
    {
        return $this->repository->paginate();
    }
}
