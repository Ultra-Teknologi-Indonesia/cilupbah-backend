<?php

namespace App\Filters;

use Spatie\QueryBuilder\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

class FuzzyFilter implements Filter
{
    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        if (is_array($value)) {
            $value = implode(' ', $value);
        }

        $query->where(function (Builder $q) use ($property, $value) {
            // 1. ILIKE untuk kecocokan parsial yang standar (misal "sepatu" cocok dengan "sepatu bola")
            $q->where($property, 'ILIKE', "%{$value}%")
              // 2. Operator <% (pg_trgm word similarity) untuk toleransi typo pada kata tertentu di kalimat panjang
              ->orWhereRaw("? <% {$property}", [$value]);
        });
    }
}
