<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{

    public function register(): void
    {

        $this->app->singleton(\Illuminate\Cache\RateLimiter::class, function ($app) {
            return new \Illuminate\Cache\RateLimiter(
                $app->make('cache')->store(config('ratelimit.store'))
            );
        });
    }

    public function boot(): void
    {
        \Illuminate\Support\Facades\Gate::before(function ($user, $ability) {
            return $user->hasRole('owner') ? true : null;
        });

        \Laravel\Sanctum\Sanctum::usePersonalAccessTokenModel(\App\Models\PersonalAccessToken::class);

        if (config('app.env') === 'production' || config('app.env') === 'staging') {
            \Illuminate\Support\Facades\URL::forceScheme('https');
            \Illuminate\Support\Facades\DB::disableQueryLog();
        }

        \Illuminate\Support\Facades\RateLimiter::for('tiktok_api', function (object $job) {

            $shopId = (property_exists($job, 'payload') && is_array($job->payload))
                ? ($job->payload['shop_id'] ?? 'default')
                : 'default';
            return \Illuminate\Cache\RateLimiting\Limit::perSecond(20)->by($shopId);
        });

        \Illuminate\Support\Facades\RateLimiter::for('channel_api', function (object $job) {

            $shopId = property_exists($job, 'channelShopId') ? $job->channelShopId : 'default';
            return \Illuminate\Cache\RateLimiting\Limit::perSecond(8)->by($shopId);
        });

        \Illuminate\Support\Facades\RateLimiter::for('webhook_download', function (object $job) {

            $shopId = 'default';

            if (property_exists($job, 'shopId') && (string) $job->shopId !== '') {
                $shopId = (string) $job->shopId;
            } elseif (property_exists($job, 'payload') && is_array($job->payload)) {
                $shopId = (string) ($job->payload['shop_id'] ?? $job->payload['seller_id'] ?? 'default');
            }

            return \Illuminate\Cache\RateLimiting\Limit::perSecond(10)->by($shopId);
        });

        \Illuminate\Support\Facades\RateLimiter::for('login', function (\Illuminate\Http\Request $request) {
            $email = \Illuminate\Support\Str::lower(trim((string) $request->input('email')));
            $ip = (string) $request->ip();

            return [
                \Illuminate\Cache\RateLimiting\Limit::perMinute((int) config('ratelimit.login.per_email', 10))
                    ->by($email !== '' ? 'login|'.$email.'|'.$ip : 'login|ip|'.$ip),
                \Illuminate\Cache\RateLimiting\Limit::perMinute((int) config('ratelimit.login.per_ip', 60))
                    ->by('login-ip|'.$ip),
            ];
        });

        \Illuminate\Support\Facades\RateLimiter::for('forgot_password', function (\Illuminate\Http\Request $request) {
            $email = \Illuminate\Support\Str::lower(trim((string) $request->input('email')));
            $ip = (string) $request->ip();
            $action = (string) ($request->route()?->getName() ?? 'forgot');

            return [
                \Illuminate\Cache\RateLimiting\Limit::perMinute((int) config('ratelimit.forgot_password.per_email', 10))
                    ->by($email !== '' ? 'forgot|'.$action.'|'.$email.'|'.$ip : 'forgot|'.$action.'|ip|'.$ip),
                \Illuminate\Cache\RateLimiting\Limit::perMinute((int) config('ratelimit.forgot_password.per_ip', 60))
                    ->by('forgot-ip|'.$ip),
            ];
        });

        \Illuminate\Support\Facades\RateLimiter::for('api', function (\Illuminate\Http\Request $request) {
            $name = (string) ($request->route()?->getName() ?? '');

            if (str_contains($name, '.webhook') || str_contains($name, '.callback')) {
                return \Illuminate\Cache\RateLimiting\Limit::none();
            }

            $user = $request->user('sanctum');

            if ($user) {
                return \Illuminate\Cache\RateLimiting\Limit::perMinute((int) config('ratelimit.api.per_identity', 300))
                    ->by('api|u|'.$user->getAuthIdentifier());
            }

            return \Illuminate\Cache\RateLimiting\Limit::perMinute((int) config('ratelimit.api.per_ip', 600))
                ->by('api|ip|'.$request->ip());
        });

        \Illuminate\Support\Facades\RateLimiter::for('heavy', function (\Illuminate\Http\Request $request) {
            $user = $request->user('sanctum');
            $key = $user ? 'heavy|u|'.$user->getAuthIdentifier() : 'heavy|ip|'.$request->ip();

            return \Illuminate\Cache\RateLimiting\Limit::perMinute((int) config('ratelimit.heavy.per_identity', 30))->by($key);
        });

        \Illuminate\Database\Eloquent\Builder::macro('allowedSearch', function (...$columns) {
            return \App\Support\AllowedSearch::apply($this, $columns);
        });

        \Illuminate\Database\Eloquent\Model::preventLazyLoading(
            (bool) config('database.prevent_lazy_loading', false)
        );

        $storageDirs = [
            storage_path('app'),
            storage_path('app/private'),
            storage_path('app/private/imports'),
            storage_path('app/private/imports/products'),
            storage_path('app/private/imports/sales-orders'),
            storage_path('app/private/imports/rack-allocation'),
            storage_path('app/private/exports'),
            storage_path('app/imports'),
            storage_path('app/exports'),
            storage_path('app/public'),
            storage_path('framework'),
            storage_path('framework/cache'),
            storage_path('framework/cache/laravel-excel'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
            storage_path('logs'),
        ];
        foreach ($storageDirs as $dir) {
            if (! is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
        }
    }
}
