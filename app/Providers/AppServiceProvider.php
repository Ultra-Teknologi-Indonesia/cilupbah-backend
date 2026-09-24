<?php

namespace App\Providers;

use App\Database\PostgresConnection;
use App\Models\PersonalAccessToken;
use App\Support\AllowedSearch;
use App\Support\OriginalExceptionFailedJobProvider;
use App\Support\QueueFailureRecorder;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Connection::resolverFor('pgsql', function ($connection, $database, $prefix, $config) {
            return new PostgresConnection($connection, $database, $prefix, $config);
        });

        $this->app->extend('queue.failer', function ($failer, $app) {
            $config = $app['config']['queue.failed'];

            if (($config['driver'] ?? null) !== 'database-uuids') {
                return $failer;
            }

            return new OriginalExceptionFailedJobProvider(
                $app['db'],
                $config['database'],
                $config['table'],
            );
        });

        $this->app->singleton(RateLimiter::class, function ($app) {
            return new RateLimiter(
                $app->make('cache')->store(config('ratelimit.store'))
            );
        });
    }

    public function boot(): void
    {
        Event::listen(
            JobExceptionOccurred::class,
            [QueueFailureRecorder::class, 'recordException'],
        );
        Event::listen(
            JobFailed::class,
            [QueueFailureRecorder::class, 'recordFailed'],
            -100,
        );

        Gate::before(function ($user, $ability) {
            return $user->hasRole('owner') ? true : null;
        });

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        if (config('app.env') === 'production' || config('app.env') === 'staging') {
            URL::forceScheme('https');
            DB::disableQueryLog();
        }

        \Illuminate\Support\Facades\RateLimiter::for('tiktok_api', function (object $job) {

            $shopId = (property_exists($job, 'payload') && is_array($job->payload))
                ? ($job->payload['shop_id'] ?? 'default')
                : 'default';

            return Limit::perSecond(20)->by($shopId);
        });

        \Illuminate\Support\Facades\RateLimiter::for('channel_api', function (object $job) {
            $payload = isset($job->payload) && is_array($job->payload)
                ? $job->payload
                : [];
            $shopId = isset($job->channelShopId)
                ? trim((string) $job->channelShopId)
                : (isset($job->shopId)
                    ? trim((string) $job->shopId)
                    : trim((string) ($payload['channel_shop_id'] ?? $payload['shop_id'] ?? '')));
            $channel = isset($job->channel)
                ? trim((string) $job->channel)
                : trim((string) ($payload['channel'] ?? $payload['source'] ?? 'channel'));

            if ($channel === 'channel') {
                $class = strtolower(get_class($job));
                $channel = str_contains($class, 'lazada') ? 'lazada'
                    : (str_contains($class, 'shopee') ? 'shopee'
                        : (str_contains($class, 'tiktok') ? 'tiktok' : $channel));
            }

            $scope = $shopId !== '' ? $channel.'|'.$shopId : $channel.'|'.get_class($job);

            return Limit::perSecond(
                (int) config(
                    'ratelimit.channel_api_per_second_by_channel.'.$channel,
                    config('ratelimit.channel_api_per_second', 8),
                ),
            )->by('channel-api|'.$scope);
        });

        \Illuminate\Support\Facades\RateLimiter::for('webhook_download', function (object $job) {

            $shopId = 'default';

            if (property_exists($job, 'shopId') && (string) $job->shopId !== '') {
                $shopId = (string) $job->shopId;
            } elseif (property_exists($job, 'payload') && is_array($job->payload)) {
                $shopId = (string) ($job->payload['shop_id'] ?? $job->payload['seller_id'] ?? 'default');
            }

            return Limit::perSecond(10)->by($shopId);
        });

        \Illuminate\Support\Facades\RateLimiter::for('login', function (Request $request) {
            $email = Str::lower(trim((string) $request->input('email')));
            $ip = (string) $request->ip();

            return [
                Limit::perMinute((int) config('ratelimit.login.per_email', 10))
                    ->by($email !== '' ? 'login|'.$email.'|'.$ip : 'login|ip|'.$ip),
                Limit::perMinute((int) config('ratelimit.login.per_ip', 60))
                    ->by('login-ip|'.$ip),
            ];
        });

        \Illuminate\Support\Facades\RateLimiter::for('forgot_password', function (Request $request) {
            $email = Str::lower(trim((string) $request->input('email')));
            $ip = (string) $request->ip();
            $action = (string) ($request->route()?->getName() ?? 'forgot');

            return [
                Limit::perMinute((int) config('ratelimit.forgot_password.per_email', 10))
                    ->by($email !== '' ? 'forgot|'.$action.'|'.$email.'|'.$ip : 'forgot|'.$action.'|ip|'.$ip),
                Limit::perMinute((int) config('ratelimit.forgot_password.per_ip', 60))
                    ->by('forgot-ip|'.$ip),
            ];
        });

        \Illuminate\Support\Facades\RateLimiter::for('api', function (Request $request) {
            $name = (string) ($request->route()?->getName() ?? '');

            if (str_contains($name, '.webhook') || str_contains($name, '.callback')) {
                return Limit::none();
            }

            $user = $request->user('sanctum');

            if ($user) {
                return Limit::perMinute((int) config('ratelimit.api.per_identity', 300))
                    ->by('api|u|'.$user->getAuthIdentifier());
            }

            return Limit::perMinute((int) config('ratelimit.api.per_ip', 600))
                ->by('api|ip|'.$request->ip());
        });

        \Illuminate\Support\Facades\RateLimiter::for('heavy', function (Request $request) {
            $user = $request->user('sanctum');
            $key = $user ? 'heavy|u|'.$user->getAuthIdentifier() : 'heavy|ip|'.$request->ip();

            return Limit::perMinute((int) config('ratelimit.heavy.per_identity', 30))->by($key);
        });

        $stockCutoverLimit = static function (Request $request, string $action, int $default): Limit {
            $tokenFingerprint = substr(hash('sha256', (string) $request->route('token')), 0, 16);

            return Limit::perMinute(
                (int) config('ratelimit.stock_cutover.'.$action.'_per_minute', $default),
            )->by('stock-cutover|'.$action.'|'.$request->ip().'|'.$tokenFingerprint);
        };

        \Illuminate\Support\Facades\RateLimiter::for(
            'stock_cutover_page',
            fn (Request $request): Limit => $stockCutoverLimit($request, 'page', 30),
        );

        \Illuminate\Support\Facades\RateLimiter::for(
            'stock_cutover_preview',
            fn (Request $request): Limit => $stockCutoverLimit($request, 'preview', 10),
        );

        \Illuminate\Support\Facades\RateLimiter::for(
            'stock_cutover_status',
            fn (Request $request): Limit => $stockCutoverLimit($request, 'status', 180),
        );

        \Illuminate\Support\Facades\RateLimiter::for(
            'stock_cutover_apply',
            fn (Request $request): Limit => $stockCutoverLimit($request, 'apply', 10),
        );

        \Illuminate\Support\Facades\RateLimiter::for(
            'stock_cutover_report',
            fn (Request $request): Limit => $stockCutoverLimit($request, 'report', 60),
        );

        $orderCutoverLimit = static function (Request $request, string $action, int $default): Limit {
            $tokenFingerprint = substr(hash('sha256', (string) $request->route('token')), 0, 16);

            return Limit::perMinute(
                (int) config('ratelimit.order_cutover.'.$action.'_per_minute', $default),
            )->by('order-cutover|'.$action.'|'.$request->ip().'|'.$tokenFingerprint);
        };

        foreach (['page' => 30, 'preview' => 10, 'status' => 180, 'apply' => 10, 'report' => 60] as $action => $default) {
            \Illuminate\Support\Facades\RateLimiter::for(
                'order_cutover_'.$action,
                fn (Request $request): Limit => $orderCutoverLimit($request, $action, $default),
            );
        }

        Builder::macro('allowedSearch', function (...$columns) {
            return AllowedSearch::apply($this, $columns);
        });

        Model::preventLazyLoading(
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
