<?php

declare(strict_types=1);

namespace Modules\Report\Services;

use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Report\Models\ExportJob;
use Modules\Report\Repositories\ExportJobRepository;

final class ExportJobService
{
    public function __construct(
        private readonly ExportJobRepository $repository,
    ) {}

    public function paginate(User $user, int $perPage = 20): LengthAwarePaginator
    {
        return $this->repository->paginateForUser((string) $user->id, $perPage);
    }

    public function findOwnedOrFail(User $user, string $exportId): ExportJob
    {
        return $this->repository->findOwnedOrFail($exportId, (string) $user->id);
    }
}
