<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Implementation behind the `allowedSearch` Eloquent macro.
 *
 * Two things matter for performance here:
 *
 * 1. The emitted SQL is built through SearchExpression so it matches the GIN
 *    expression indexes (full-text + pg_trgm) created in the migrations. Without
 *    that, every ?search= hit degrades into a sequential scan that also has to
 *    build a tsvector per row.
 * 2. Relevance ordering (ts_rank_cd) is only added when the caller did not ask
 *    for an explicit sort. ts_rank_cd forces the planner to materialise and sort
 *    the whole match set; skipping it lets an ordered index answer `LIMIT n`
 *    directly. It also means an explicit ?sort= is finally honoured instead of
 *    being silently overridden by the rank.
 */
class AllowedSearch
{
    public static function apply(Builder $builder, array $columns): Builder
    {
        $term = trim((string) request()->query('search', ''));

        if ($term === '' || empty($columns)) {
            return $builder;
        }

        [$local, $relations] = self::partition($builder, $columns);

        if (empty($local) && empty($relations)) {
            return $builder;
        }

        if (empty($relations)) {
            $builder->whereRaw(
                SearchExpression::match($local),
                SearchExpression::matchBindings($term, $local)
            );
        } else {
            $builder->where(function (Builder $query) use ($local, $relations, $term) {
                if (! empty($local)) {
                    $query->whereRaw(
                        SearchExpression::match($local),
                        SearchExpression::matchBindings($term, $local)
                    );
                }

                foreach ($relations as $relation => $cols) {
                    $query->orWhereHas($relation, function ($sub) use ($cols, $term) {
                        $sub->whereRaw(
                            SearchExpression::match($cols),
                            SearchExpression::matchBindings($term, $cols)
                        );
                    });
                }
            });
        }

        if (! empty($local) && self::wantsRelevanceOrder()) {
            $builder->orderByRaw(SearchExpression::rank($local).' DESC', [$term]);
        }

        return $builder;
    }

    /**
     * Splits `relation.column` arguments from plain columns on the base table.
     *
     * @return array{0: array<int, string>, 1: array<string, array<int, string>>}
     */
    private static function partition(Builder $builder, array $columns): array
    {
        $model = $builder->getModel();
        $baseTable = $model->getTable();

        $local = [];
        $relations = [];

        foreach ($columns as $column) {
            if (! is_string($column) || $column === '') {
                continue;
            }

            if (! str_contains($column, '.')) {
                $local[] = $column;

                continue;
            }

            [$prefix, $col] = explode('.', $column, 2);

            if ($prefix === $baseTable || ! method_exists($model, $prefix)) {
                $local[] = $column;
            } else {
                $relations[$prefix][] = $col;
            }
        }

        return [$local, $relations];
    }

    private static function wantsRelevanceOrder(): bool
    {
        return blank(request()->query('sort'));
    }
}
