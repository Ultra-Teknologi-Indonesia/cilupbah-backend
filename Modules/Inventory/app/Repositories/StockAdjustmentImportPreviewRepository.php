<?php

declare(strict_types=1);

namespace Modules\Inventory\Repositories;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

final class StockAdjustmentImportPreviewRepository
{
    public const CACHE_PREFIX = 'stock-adjustment-import:';

    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    private const SEARCHABLE_FIELDS = ['sku', 'product_name', 'bin_code'];

    private const SORTABLE_FIELDS = [
        'row_no', 'sku', 'product_name', 'bin_code', 'mode',
        'input_value', 'system_qty', 'actual_qty', 'difference',
    ];

    public function put(string $token, array $preview, int $ttlMinutes): void
    {
        Cache::put(
            self::CACHE_PREFIX.$token,
            $preview,
            Carbon::now()->addMinutes($ttlMinutes),
        );
    }

    public function find(string $token, ?string $actorId = null): ?array
    {
        $preview = Cache::get(self::CACHE_PREFIX.$token);

        if (! is_array($preview)) {
            return null;
        }

        if (array_key_exists('actor_id', $preview) && $preview['actor_id'] !== $actorId) {
            return null;
        }

        return $preview;
    }

    public function forget(string $token): void
    {
        Cache::forget(self::CACHE_PREFIX.$token);
    }

    public function paginate(array $preview, string $token, array $query = []): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(
            self::MAX_PER_PAGE,
            max(1, (int) ($query['per_page'] ?? self::DEFAULT_PER_PAGE)),
        );
        $search = trim((string) ($query['search'] ?? ''));
        $sort = (string) ($query['sort'] ?? 'row_no');
        $descending = str_starts_with($sort, '-');
        $sortField = ltrim($sort, '-');

        if (! in_array($sortField, self::SORTABLE_FIELDS, true)) {
            $sortField = 'row_no';
            $descending = false;
        }

        $items = collect($preview['items'] ?? []);
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $items = $items->filter(function (array $item) use ($needle): bool {
                foreach (self::SEARCHABLE_FIELDS as $field) {
                    if (str_contains(mb_strtolower((string) ($item[$field] ?? '')), $needle)) {
                        return true;
                    }
                }

                return false;
            });
        }

        $numericSorts = ['row_no', 'input_value', 'system_qty', 'actual_qty', 'difference'];
        $items = $items->sortBy(
            fn (array $item): mixed => in_array($sortField, $numericSorts, true)
                ? (float) ($item[$sortField] ?? 0)
                : mb_strtolower((string) ($item[$sortField] ?? '')),
            SORT_REGULAR,
            $descending,
        )->values();

        $total = $items->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        return [
            'token' => $token,
            'items' => $items->forPage($page, $perPage)->values()->all(),
            'errors' => $preview['errors'] ?? [],
            'warnings' => $preview['warnings'] ?? [],
            'summary' => $preview['summary'] ?? [
                'total_rows' => 0,
                'valid' => 0,
                'errors' => 0,
                'warnings' => 0,
            ],
            'pagination' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
            'query' => [
                'search' => $search,
                'sort' => $descending ? '-'.$sortField : $sortField,
            ],
        ];
    }
}
