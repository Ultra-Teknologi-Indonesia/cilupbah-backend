<?php

namespace App\Support;

/**
 * Single source of truth for the SQL emitted by the `allowedSearch` macro.
 *
 * PostgreSQL only uses an expression index when the expression in the query is
 * textually identical to the one the index was built on. Both the runtime query
 * (App\Support\AllowedSearch) and the migrations that create the GIN indexes
 * build their SQL from here so the two can never drift apart.
 */
class SearchExpression
{
    public const CONFIG = 'indonesian';

    /**
     * Canonical column order.
     *
     * Call sites spell the same column set in different orders
     * (`allowedSearch('name', 'sku')` vs `allowedSearch('sku', 'name')`), which
     * would otherwise produce two different expressions and therefore need two
     * different indexes. Sorting here makes the expression depend on the set
     * alone, so a single index serves every call site on a table.
     *
     * @return array<int, string>
     */
    public static function normalize(array $columns): array
    {
        $columns = array_values(array_unique(array_filter(
            $columns,
            fn ($column) => is_string($column) && $column !== ''
        )));

        sort($columns);

        return $columns;
    }

    /**
     * Concatenated searchable document, e.g. COALESCE(name::text, '') || ' ' || COALESCE(sku::text, '').
     */
    public static function document(array $columns): string
    {
        return collect(self::normalize($columns))
            ->map(fn ($column) => self::text($column))
            ->implode(" || ' ' || ");
    }

    /**
     * Single column coerced to text, matching the trigram index expression.
     */
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

    /**
     * Boolean match: full-text first (GIN backed), substring fallback (pg_trgm backed).
     */
    public static function match(array $columns): string
    {
        $ilike = collect(self::normalize($columns))
            ->map(fn ($column) => self::text($column).' ILIKE ?')
            ->implode(' OR ');

        return '('.self::vector($columns).' @@ '.self::tsquery()." OR ({$ilike}))";
    }

    /**
     * Bindings for match(): the raw term for tsquery, then one LIKE pattern per column.
     */
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
