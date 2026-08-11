<?php

namespace Modules\Product\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class ProductFeedQuery
{
    public const SEARCHABLE = ['name', 'sku'];

    public const SORTS = ['name', 'created_at', 'updated_at'];

    public const DEFAULT_SORT = '-updated_at';

    public static function configure(QueryBuilder $query): QueryBuilder
    {
        return self::applyCriteria($query)
            ->allowedSorts(...self::SORTS)
            ->defaultSort(self::DEFAULT_SORT);
    }

    public static function applyCriteria(QueryBuilder $query): QueryBuilder
    {
        return $query
            ->allowedSearch(...self::SEARCHABLE)
            ->allowedFilters(...self::filters());
    }

    /**
     * Same criteria as applyCriteria(), but applied to a plain Eloquent builder
     * so they can be nested inside an OR group. The merge-aware feed needs this:
     * it has to express "this product matches, OR it represents a merged member
     * that matches" without materialising the whole match set in PHP.
     *
     * Returns whether any constraint was actually added. hasCriteria() only sees
     * that the request carries a search/filter key — a value the filters cannot
     * act on (an unknown key, or filter[type]=garbage) still constrains nothing,
     * and the caller must know that to avoid building a narrowing OR group out of
     * an empty left-hand side.
     */
    public static function applyCriteriaTo(Builder $query): bool
    {
        $before = count($query->getQuery()->wheres);

        $query->allowedSearch(...self::SEARCHABLE);

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

        return count($query->getQuery()->wheres) > $before;
    }

    /**
     * Dry run of applyCriteriaTo() against a throwaway builder — no query is executed.
     */
    public static function criteriaAreEffective(Builder $probe): bool
    {
        return self::applyCriteriaTo($probe);
    }

    public static function applySort(Builder $query): Builder
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

    /**
     * Resolves a category and all of its descendants with a single recursive CTE.
     *
     * The previous implementation pulled every row of `categories` into PHP and
     * walked the tree there — with channel-imported taxonomies that table runs to
     * tens of thousands of rows, paid on every filtered request.
     *
     * @return array<int, int>
     */
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
