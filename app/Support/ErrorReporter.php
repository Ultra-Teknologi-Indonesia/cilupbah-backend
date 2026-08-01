<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Throwable;

class ErrorReporter
{
    public static function captureThrottled(Throwable $e, string $key, array $tags = [], int $seconds = 300): void
    {
        if (empty(config('sentry.dsn'))) {
            return;
        }

        if (! Cache::add('sentry:throttle:' . md5($key), 1, $seconds)) {
            return;
        }

        \Sentry\withScope(function (\Sentry\State\Scope $scope) use ($e, $tags): void {
            foreach ($tags as $name => $value) {
                $scope->setTag($name, (string) $value);
            }

            \Sentry\captureException($e);
        });
    }
}
