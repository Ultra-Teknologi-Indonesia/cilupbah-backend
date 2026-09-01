<?php

namespace Modules\Product\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

final class TechnicalSku
{
    public const BUNDLE_PREFIX = '__bundle__';

    public static function isTechnical(?string $sku): bool
    {
        return is_string($sku) && str_starts_with($sku, self::BUNDLE_PREFIX);
    }

    public static function exclude(Builder|QueryBuilder $query, string $column = 'sku'): Builder|QueryBuilder
    {
        return $query->where(function ($scope) use ($column) {
            $scope->whereNull($column)
                ->orWhereRaw('LEFT(LOWER('.$column.'), ?) <> ?', [strlen(self::BUNDLE_PREFIX), self::BUNDLE_PREFIX]);
        });
    }

    public static function whereTechnical(Builder|QueryBuilder $query, string $column = 'sku'): Builder|QueryBuilder
    {
        return $query->whereRaw(
            'LEFT(LOWER('.$column.'), ?) = ?',
            [strlen(self::BUNDLE_PREFIX), self::BUNDLE_PREFIX]
        );
    }
}
