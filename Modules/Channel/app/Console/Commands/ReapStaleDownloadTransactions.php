<?php

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Models\DownloadTransaction;

class ReapStaleDownloadTransactions extends Command
{
    protected $signature = 'channel-downloads:reap-stale {--minutes=20 : Transaksi downloading lebih tua dari N menit dianggap macet}';

    protected $description = 'Tandai FAILED transaksi download yang stuck DOWNLOADING (safety net kalau job crash/timeout tanpa memanggil failed()).';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        $threshold = now()->subMinutes($minutes);

        $stale = DownloadTransaction::query()
            ->where('state', DownloadTransaction::STATE_DOWNLOADING)
            ->where('updated_at', '<', $threshold)
            ->get();

        if ($stale->isEmpty()) {
            $this->info('Tidak ada transaksi download macet.');
            return self::SUCCESS;
        }

        foreach ($stale as $transaction) {
            $this->warn("Reap transaksi {$transaction->id} (updated_at={$transaction->updated_at})");
            $transaction->markFailed("Download dihentikan otomatis: tidak ada progres lebih dari {$minutes} menit (job kemungkinan timeout atau crash).");
        }

        $this->info("Reaped {$stale->count()} transaksi download.");
        return self::SUCCESS;
    }
}
