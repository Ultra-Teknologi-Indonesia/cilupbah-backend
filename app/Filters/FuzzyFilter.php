<?php

namespace App\Filters;

use Illuminate\Database\Eloquent\Builder;
use Spatie\QueryBuilder\Filters\Filter;

class FuzzyFilter implements Filter
{
    protected string $columns;

    public function __construct(string $columns = '')
    {
        $this->columns = $columns;
    }

    public function __invoke(Builder $query, $value, string $property): void
    {
        $columns = array_filter(explode(',', $this->columns ?: $property));

        $query->where(function (Builder $q) use ($columns, $value) {
            foreach ($columns as $column) {
                $q->orWhere($column, 'ILIKE', "%{$value}%");
            }
        });
    }
}
