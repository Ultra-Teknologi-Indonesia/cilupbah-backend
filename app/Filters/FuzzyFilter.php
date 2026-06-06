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
            $q->where($property, 'ILIKE', "%{$value}%")
              ->orWhereRaw("? <% {$property}", [$value]);
        });
    }
}
