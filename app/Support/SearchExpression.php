<?php

namespace App\Support;

class SearchExpression
{
    public const CONFIG = 'indonesian';

    public static function normalize(array $columns): array
    {
        $columns = array_values(array_unique(array_filter(
            $columns,
            fn ($column) => is_string($column) && $column !== ''
        )));

        sort($columns);

        return $columns;
    }

    public static function document(array $columns): string
    {
        return collect(self::normalize($columns))
            ->map(fn ($column) => self::text($column))
            ->implode(" || ' ' || ");
    }

    public static function text(string $column): string
    {
        return "COALESCE({$column}::text, '')";
    }

    public static function vector(array $columns): string
    {
        return "to_tsvector('".self::CONFIG."', ".self::document($columns).')';
    }

    public static function tsquery(): string
    {
        return "websearch_to_tsquery('".self::CONFIG."', ?)";
    }

    public static function rank(array $columns): string
    {
        return 'ts_rank_cd('.self::vector($columns).', '.self::tsquery().')';
    }

    public static function match(array $columns): string
    {
        $ilike = collect(self::normalize($columns))
            ->map(fn ($column) => self::text($column).' ILIKE ?')
            ->implode(' OR ');

        return '('.self::vector($columns).' @@ '.self::tsquery()." OR ({$ilike}))";
    }

    public static function matchBindings(string $term, array $columns): array
    {
        return array_merge(
            [$term],
            array_fill(0, count(self::normalize($columns)), self::like($term))
        );
    }

    public static function like(string $term): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';
    }
}
