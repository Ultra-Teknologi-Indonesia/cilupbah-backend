<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Support\Facades\Gate::before(function ($user, $ability) {
            return $user->hasRole('owner') ? true : null;
        });

        \App\Models\User::observe(\App\Observers\UserObserver::class);

        \Laravel\Sanctum\Sanctum::usePersonalAccessTokenModel(\App\Models\PersonalAccessToken::class);

        if (config('app.env') === 'production' || config('app.env') === 'staging') {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        \Illuminate\Support\Facades\RateLimiter::for('tiktok_api', function ($job) {
            return \Illuminate\Cache\RateLimiting\Limit::perSecond(20);
        });

        \Illuminate\Support\Facades\RateLimiter::for('channel_api', function (object $job) {
            // TikTok rate limit: ~10 req/sec per shop (set to 8 for safety)
            // Need to check if the job has channelShopId property
            $shopId = property_exists($job, 'channelShopId') ? $job->channelShopId : 'default';
            return \Illuminate\Cache\RateLimiting\Limit::perSecond(8)->by($shopId);
        });

        \Illuminate\Database\Eloquent\Builder::macro('allowedSearch', function (...$columns) {
            /** @var \Illuminate\Database\Eloquent\Builder $this */
            $search = request()->query('search');

            if (empty($search)) {
                return $this;
            }

            $columnsStr = collect($columns)
                ->map(fn ($column) => "COALESCE({$column}::text, '')")
                ->implode(" || ' ' || ");

            $this->whereRaw("to_tsvector('indonesian', {$columnsStr}) @@ websearch_to_tsquery('indonesian', ?)", [$search])
                 ->orderByRaw("ts_rank_cd(to_tsvector('indonesian', {$columnsStr}), websearch_to_tsquery('indonesian', ?)) DESC", [$search]);

            return $this;
        });
    }
}
