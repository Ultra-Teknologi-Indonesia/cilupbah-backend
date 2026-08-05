<?php

namespace Modules\Channel\Support;

use Illuminate\Support\Facades\Log;

class ChannelRetry
{
    /**
     * Jalankan $fn dengan retry untuk error yang bersifat sementara (rate-limit / 5xx / timeout).
     * Error non-retryable (validasi, token gagal, dsb.) langsung dilempar tanpa menunda.
     */
    public static function run(string $channelCode, callable $fn, ?int $maxAttempts = null, ?callable $sleeper = null)
    {
        $maxAttempts = $maxAttempts ?? (int) config('channel.download_retry_attempts', 4);
        $maxAttempts = max(1, $maxAttempts);
        $sleeper = $sleeper ?? static fn (int $seconds) => sleep($seconds);

        $attempt = 0;

        while (true) {
            try {
                return $fn();
            } catch (\Throwable $e) {
                $attempt++;

                if ($attempt >= $maxAttempts || ! ChannelErrorClassifier::isRetryable($channelCode, $e)) {
                    throw $e;
                }

                $wait = ChannelErrorClassifier::retryAfterSeconds($e) ?? min(2 ** $attempt, 30);

                Log::warning("Channel {$channelCode}: error sementara (percobaan {$attempt}/{$maxAttempts}), tunggu {$wait}s lalu ulang.", [
                    'channel' => $channelCode,
                    'attempt' => $attempt,
                    'wait_seconds' => $wait,
                    'error' => $e->getMessage(),
                ]);

                $sleeper($wait);
            }
        }
    }
}
