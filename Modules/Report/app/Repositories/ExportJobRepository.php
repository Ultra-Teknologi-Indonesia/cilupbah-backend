<?php

declare(strict_types=1);

namespace Modules\Report\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Report\Models\ExportJob;
use Modules\Report\Support\ExportCatalog;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

final class ExportJobRepository
{
    public function paginateForUser(string $userId, int $perPage = 20): LengthAwarePaginator
    {
        $query = QueryBuilder::for(ExportJob::class)
            ->select([
                'id',
                'user_id',
                'type',
                'params',
                'status',
                'file_name',
                'file_size',
                'file_path',
                'file_purged_at',
                'error',
                'created_at',
                'started_at',
                'finished_at',
            ])
            ->where('user_id', $userId)
            ->allowedFilters(
                AllowedFilter::callback('search', function (Builder $query, mixed $value): void {
                    $search = trim((string) $value);
                    if ($search === '') {
                        return;
                    }

                    $typeMatches = ExportCatalog::typesMatchingSearch($search);
                    $prefix = $this->escapeLike($search);

                    $query->where(function (Builder $searchQuery) use ($prefix, $typeMatches): void {
                        $searchQuery
                            ->where('file_name', 'like', $prefix.'%')
                            ->orWhere('id', 'like', $prefix.'%');

                        if ($typeMatches !== []) {
                            $searchQuery->orWhereIn('type', $typeMatches);
                        }
                    });
                }),
                AllowedFilter::exact('type'),
                AllowedFilter::callback('category', static function (Builder $query, mixed $value): void {
                    $query->whereIn('type', ExportCatalog::typesForCategory((string) $value));
                }),
                AllowedFilter::callback('format', static function (Builder $query, mixed $value): void {
                    $query->whereIn('type', ExportCatalog::typesForFormat((string) $value));
                }),
                AllowedFilter::callback('status', static function (Builder $query, mixed $value): void {
                    $status = (string) $value;

                    if ($status === ExportJob::STATUS_EXPIRED) {
                        $query->whereNotNull('file_purged_at');
                    } elseif ($status === ExportJob::STATUS_READY) {
                        $query->where('status', ExportJob::STATUS_READY)
                            ->whereNull('file_purged_at');
                    } else {
                        $query->where('status', $status)->whereNull('file_purged_at');
                    }
                }),
                AllowedFilter::callback('created_from', static function (Builder $query, mixed $value): void {
                    $query->whereDate('created_at', '>=', (string) $value);
                }),
                AllowedFilter::callback('created_to', static function (Builder $query, mixed $value): void {
                    $query->whereDate('created_at', '<=', (string) $value);
                }),
            )
            ->allowedSorts(
                AllowedSort::field('created_at', 'export_jobs.created_at'),
                AllowedSort::field('finished_at', 'export_jobs.finished_at'),
                AllowedSort::field('status', 'export_jobs.status'),
                AllowedSort::field('type', 'export_jobs.type'),
                AllowedSort::field('file_name', 'export_jobs.file_name'),
                AllowedSort::field('file_purged_at', 'export_jobs.file_purged_at'),
            )
            ->defaultSort('-created_at')
            ->orderByDesc('export_jobs.id');

        return $query->paginate($perPage)->withQueryString();
    }

    public function findOwnedOrFail(string $exportId, string $userId): ExportJob
    {
        $job = ExportJob::query()->findOrFail($exportId);

        abort_unless($job->user_id === $userId, 403);

        return $job;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
