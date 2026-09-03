<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Finance\Repositories\AccountRepository;

final class AccountService
{
    public function __construct(
        private readonly AccountRepository $repository,
    ) {}

    public function activeLookup(?string $type = null): Collection
    {
        return $this->repository->getActiveLookup($type);
    }
}
