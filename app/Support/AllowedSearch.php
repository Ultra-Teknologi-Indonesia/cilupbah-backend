<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

class AllowedSearch
{
    public static function apply(Builder $builder, array $columns): Builder
    {
        $term = trim((string) (request()->query('search') ?? request()->query('q', '')));

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
