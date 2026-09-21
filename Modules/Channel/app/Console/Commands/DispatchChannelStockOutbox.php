<?php

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Services\ChannelStockSyncOutboxService;

class DispatchChannelStockOutbox extends Command
{
    protected $signature = 'channel:dispatch-stock-outbox
        {--limit= : Jumlah maksimum listing yang dijadwalkan pada satu putaran}';

    protected $description = 'Menjadwalkan sinkronisasi stok/price terbaru per listing sesuai kuota API channel.';

    public function handle(ChannelStockSyncOutboxService $outbox): int
    {
        $rawLimit = (string) $this->option('limit');
        if ($rawLimit === '') {
            $rawLimit = (string) config('channel.stock_sync_dispatch_claim_limit', 50);
        }

        if (! ctype_digit($rawLimit) || (int) $rawLimit < 1) {
            $this->error('--limit harus berupa bilangan bulat positif.');

            return self::FAILURE;
        }

        $result = $outbox->dispatchDue((int) $rawLimit);
        $this->info('Channel stock outbox diproses.');
        $this->line('Lease kedaluwarsa dipulihkan: '.$result['reaped']);
        $this->line('Pengiriman pending lama dihidupkan kembali: '.$result['revived']);
        $this->line('Listing dijadwalkan: '.$result['claimed']);

        foreach ($result['byChannel'] as $channel => $total) {
            $this->line("- {$channel}: {$total}");
        }

        return self::SUCCESS;
    }
}
