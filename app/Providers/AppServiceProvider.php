<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{

    public function register(): void
    {

    }

    public function boot(): void
    {
        \Illuminate\Support\Facades\Gate::before(function ($user, $ability) {
            return $user->hasRole('owner') ? true : null;
        });

        \Laravel\Sanctum\Sanctum::usePersonalAccessTokenModel(\App\Models\PersonalAccessToken::class);

        if (config('app.env') === 'production' || config('app.env') === 'staging') {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        \Illuminate\Support\Facades\RateLimiter::for('tiktok_api', function ($job) {
            return \Illuminate\Cache\RateLimiting\Limit::perSecond(20);
        });

        \Illuminate\Support\Facades\RateLimiter::for('channel_api', function (object $job) {

            $shopId = property_exists($job, 'channelShopId') ? $job->channelShopId : 'default';
            return \Illuminate\Cache\RateLimiting\Limit::perSecond(8)->by($shopId);
        });

        \Illuminate\Database\Eloquent\Builder::macro('allowedSearch', function (...$columns) {

            $search = request()->query('search');

            if (empty($search)) {
                return $this;
            }

            $localColumns = [];
            $relationColumns = [];

            $model = $this->getModel();
            $baseTable = $model->getTable();

            foreach ($columns as $column) {
                if (str_contains($column, '.')) {
                    [$prefix, $col] = explode('.', $column, 2);

                    if ($prefix === $baseTable || ! method_exists($model, $prefix)) {
                        $localColumns[] = $column;
                    } else {
                        $relationColumns[$prefix][] = $col;
                    }
                } else {
                    $localColumns[] = $column;
                }
            }

            if (empty($relationColumns)) {
                $columnsStr = collect($localColumns)
                    ->map(fn ($column) => "COALESCE({$column}::text, '')")
                    ->implode(" || ' ' || ");

                $this->whereRaw("to_tsvector('indonesian', {$columnsStr}) @@ websearch_to_tsquery('indonesian', ?)", [$search])
                     ->orderByRaw("ts_rank_cd(to_tsvector('indonesian', {$columnsStr}), websearch_to_tsquery('indonesian', ?)) DESC", [$search]);
            } else {
                $this->where(function ($query) use ($localColumns, $relationColumns, $search) {
                    if (!empty($localColumns)) {
                        $columnsStr = collect($localColumns)
                            ->map(fn ($col) => "COALESCE({$col}::text, '')")
                            ->implode(" || ' ' || ");
                        $query->whereRaw("to_tsvector('indonesian', {$columnsStr}) @@ websearch_to_tsquery('indonesian', ?)", [$search]);
                    }

                    foreach ($relationColumns as $relation => $cols) {
                        $colsStr = collect($cols)
                            ->map(fn ($col) => "COALESCE({$col}::text, '')")
                            ->implode(" || ' ' || ");
                        $query->orWhereHas($relation, function ($sub) use ($colsStr, $search) {
                            $sub->whereRaw("to_tsvector('indonesian', {$colsStr}) @@ websearch_to_tsquery('indonesian', ?)", [$search]);
                        });
                    }
                });

                if (!empty($localColumns)) {
                    $columnsStr = collect($localColumns)
                        ->map(fn ($col) => "COALESCE({$col}::text, '')")
                        ->implode(" || ' ' || ");
                    $this->orderByRaw("ts_rank_cd(to_tsvector('indonesian', {$columnsStr}), websearch_to_tsquery('indonesian', ?)) DESC", [$search]);
                }
            }

            return $this;
        });
    }
}
