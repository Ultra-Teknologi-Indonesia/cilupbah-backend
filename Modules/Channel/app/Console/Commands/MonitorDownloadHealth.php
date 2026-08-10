<?php

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Models\DownloadTransaction;

class MonitorDownloadHealth extends Command
{
    protected $signature = 'channel:monitor-download-health';

    protected $description = 'Alert saat failure-rate download channel melonjak atau ada proses macet';

    public function handle(): int
    {
        $windowMinutes = (int) env('DOWNLOAD_HEALTH_WINDOW_MINUTES', 60);
        $minVolume = (int) env('DOWNLOAD_HEALTH_MIN_VOLUME', 20);
        $rateThreshold = (float) env('DOWNLOAD_HEALTH_FAIL_RATE', 0.15);
        $stuckMinutes = (int) env('DOWNLOAD_HEALTH_STUCK_MINUTES', 30);

        $alerts = $this->checkFailureRate($windowMinutes, $minVolume, $rateThreshold)
            + $this->checkStuck($stuckMinutes);

        $this->info($alerts > 0 ? "{$alerts} alert dikirim." : 'Sehat: tak ada anomali download.');

        return self::SUCCESS;
    }

    private function checkFailureRate(int $windowMinutes, int $minVolume, float $threshold): int
    {
        $rows = DownloadTransaction::query()
            ->with('channelShop.channel')
            ->where('created_at', '>=', now()->subMinutes($windowMinutes))
            ->whereIn('state', [DownloadTransaction::STATE_DONE, DownloadTransaction::STATE_FAILED])
            ->get();

        $byChannel = [];
        foreach ($rows as $t) {
            $code = $t->channelShop?->channel?->code ?? 'unknown';
            $byChannel[$code] ??= ['processed' => 0, 'failed' => 0];
            $byChannel[$code]['processed'] += (int) $t->total_downloaded + (int) $t->total_failed;
            $byChannel[$code]['failed'] += (int) $t->total_failed
                + ($t->state === DownloadTransaction::STATE_FAILED ? 1 : 0);
        }

        $alerts = 0;
        foreach ($byChannel as $code => $stat) {
            if ($stat['processed'] < $minVolume) {
                continue;
            }

            $rate = $stat['failed'] / max(1, $stat['processed']);
            if ($rate >= $threshold) {
                $this->pushAlert(
                    "Download {$code}: failure-rate " . round($rate * 100) . "% ({$stat['failed']}/{$stat['processed']})",
                    [
                        'channel' => $code,
                        'rate' => round($rate, 4),
                        'failed' => $stat['failed'],
                        'processed' => $stat['processed'],
                        'window_minutes' => $windowMinutes,
                    ],
                );
                $alerts++;
            }
        }

        return $alerts;
    }

    private function checkStuck(int $stuckMinutes): int
    {
        $stuck = DownloadTransaction::query()
            ->with('channelShop.channel')
            ->where('state', DownloadTransaction::STATE_DOWNLOADING)
            ->where('updated_at', '<', now()->subMinutes($stuckMinutes))
            ->get();

        foreach ($stuck as $t) {
            $this->pushAlert(
                "Download macet: {$t->trx_no} (" . ($t->channelShop?->channel?->code ?? '?') . ") tanpa progres > {$stuckMinutes} mnt",
                [
                    'trx_no' => $t->trx_no,
                    'channel_shop_id' => $t->channel_shop_id,
                    'stuck_minutes' => (int) $t->updated_at->diffInMinutes(now()),
                ],
            );
        }

        return $stuck->count();
    }

    private function pushAlert(string $message, array $context): void
    {
        Log::warning('[download-health] ' . $message, $context);

        if (function_exists('Sentry\\captureMessage')) {
            \Sentry\captureMessage('[download-health] ' . $message);
        }

        $this->warn($message);
    }
}
