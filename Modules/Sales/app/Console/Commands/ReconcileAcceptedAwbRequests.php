<?php

declare(strict_types=1);

namespace Modules\Sales\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Modules\Sales\Jobs\RequestChannelAwbJob;
use Modules\Sales\Models\ChannelOperationAttempt;
use Modules\Sales\Support\ChannelOperationLedger;

final class ReconcileAcceptedAwbRequests extends Command
{
    protected $signature = 'shipping-labels:reconcile-accepted-awb
        {--limit=100 : Maximum AWB operations scheduled per run}
        {--cooldown=300 : Minimum seconds between read-only verification attempts}';

    protected $description = 'Verify accepted AWB requests without sending another marketplace request';

    public function handle(): int
    {
        if (! Schema::hasTable('channel_operation_attempts')) {
            $this->warn('channel_operation_attempts belum tersedia; rekonsiliasi AWB dilewati.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $cooldownSeconds = max(60, (int) $this->option('cooldown'));
        $cutoff = now()->subSeconds($cooldownSeconds);

        $orderIds = ChannelOperationAttempt::query()
            ->where('operation', 'request_awb')
            ->where('status', ChannelOperationAttempt::STATUS_ACCEPTED)
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('updated_at')
            ->limit($limit)
            ->pluck('order_id');

        $scheduled = 0;
        foreach ($orderIds as $orderId) {
            if (! ChannelOperationLedger::beginVerification((string) $orderId, 'request_awb', $cooldownSeconds)) {
                continue;
            }

            RequestChannelAwbJob::dispatch((string) $orderId, 1, false, false, true);
            $scheduled++;
        }

        $this->info("Verifikasi AWB baca-saja dijadwalkan: {$scheduled} order.");

        return self::SUCCESS;
    }
}
