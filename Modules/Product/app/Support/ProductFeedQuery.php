<?php

namespace Modules\Product\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class ProductFeedQuery
{
    public const SEARCHABLE = ['name', 'sku', 'variants.sku'];

    public const SORTS = ['name', 'created_at', 'updated_at'];

    public const DEFAULT_SORT = '-updated_at';

    public static function configure(QueryBuilder|Builder $query): QueryBuilder|Builder
    {
        if ($query instanceof QueryBuilder) {
            return self::applyCriteria($query)
                ->allowedSorts(...self::SORTS)
                ->defaultSort(self::DEFAULT_SORT);
        }

        self::applyCriteriaTo($query);
        self::applySort($query);

        return $query;
    }

    public static function applyCriteria(QueryBuilder|Builder $query): QueryBuilder|Builder
    {
        if ($query instanceof QueryBuilder) {
            return $query
                ->allowedSearch(...self::SEARCHABLE)
                ->allowedFilters(...self::filters());
        }

        self::applyCriteriaTo($query);

        return $query;
    }

    public static function applyCriteriaTo(Builder|\Illuminate\Database\Query\Builder|QueryBuilder $query): bool
    {
        $rawQuery = method_exists($query, 'getQuery') ? $query->getQuery() : $query;
        $before = count($rawQuery->wheres ?? []);

        if (method_exists($query, 'allowedSearch')) {
            $query->allowedSearch(...self::SEARCHABLE);
        } elseif (filled(request()->query('search'))) {
            $search = (string) request()->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('sku', 'ilike', "%{$search}%")
                    ->orWhereHas('variants', fn ($vq) => $vq->where('sku', 'ilike', "%{$search}%"));
            });
        }

        foreach ((array) request()->query('filter', []) as $name => $value) {
            if (blank($value)) {
                continue;
            }

            match ($name) {
                'category_id' => self::filterCategory($query, self::splitList($value)),
                'type' => self::filterType($query, $value),
                'min_price' => self::filterMinPrice($query, $value),
                'max_price' => self::filterMaxPrice($query, $value),
                'channel' => self::filterChannel($query, $value),
                default => null,
            };
        }

        $after = count($rawQuery->wheres ?? []);

        return $after > $before;
    }

    public static function criteriaAreEffective(Builder|\Illuminate\Database\Query\Builder|QueryBuilder $probe): bool
    {
        return self::applyCriteriaTo($probe);
    }

    public static function applySort(Builder|\Illuminate\Database\Query\Builder|QueryBuilder $query)
    {
        $sort = (string) request()->query('sort', self::DEFAULT_SORT);
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        if (! in_array($column, self::SORTS, true)) {
            $column = 'updated_at';
            $direction = 'desc';
        }

        return $query->orderBy($column, $direction);
    }

    public static function hasCriteria(): bool
    {
        return filled(request()->query('search'))
            || ! empty(array_filter((array) request()->query('filter', [])));
    }

    public static function filters(): array
    {
        return [
            AllowedFilter::callback('category_id', fn ($query, $value) => self::filterCategory($query, $value)),
            AllowedFilter::callback('type', fn ($query, $value) => self::filterType($query, $value)),
            AllowedFilter::callback('min_price', fn ($query, $value) => self::filterMinPrice($query, $value)),
            AllowedFilter::callback('max_price', fn ($query, $value) => self::filterMaxPrice($query, $value)),
            AllowedFilter::callback('channel', fn ($query, $value) => self::filterChannel($query, $value)),
        ];
    }

    private static function filterCategory($query, $value): void
    {
        $query->whereIn('category_id', self::categoryWithDescendants($value));
    }

    private static function filterType($query, $value): void
    {
        match ($value) {
            'bundle' => $query->where('is_bundle', true),
            'konsinyasi' => $query->where('is_consignment', true),
            'pre_order' => $query->where('order_type', 'PREORDER'),
            'satuan' => $query->where('is_bundle', false)
                ->where('is_consignment', false)
                ->where(fn ($q) => $q->where('order_type', '<>', 'PREORDER')->orWhereNull('order_type')),
            default => null,
        };
    }

    private static function filterMinPrice($query, $value): void
    {
        $query->whereHas('variants', fn ($q) => $q->where('sell_price', '>=', $value));
    }

    private static function filterMaxPrice($query, $value): void
    {
        $query->whereHas('variants', fn ($q) => $q->where('sell_price', '<=', $value));
    }

    private static function filterChannel($query, $value): void
    {
        $query->whereHas('channelMappings.channelShop.channel', fn ($q) => $q->where('code', $value));
    }

    public static function categoryWithDescendants($values): array
    {
        $roots = array_values(array_unique(array_filter(array_map('intval', (array) $values))));

        if (empty($roots)) {
            return [0];
        }

        $placeholders = implode(',', array_fill(0, count($roots), '?'));

        $rows = DB::select(
            "WITH RECURSIVE category_tree AS (
                SELECT id FROM categories WHERE id IN ({$placeholders})
                UNION
                SELECT c.id FROM categories c JOIN category_tree t ON c.parent_id = t.id
            ) SELECT id FROM category_tree",
            $roots
        );

        $ids = array_map(static fn ($row) => (int) $row->id, $rows);

        return $ids ?: [0];
    }

    private static function splitList($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        return array_filter(array_map('trim', explode(',', (string) $value)), 'strlen');
    }
}
