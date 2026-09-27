<?php

declare(strict_types=1);

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Services\ChannelStockSyncOutboxMonitorService;

final class MonitorChannelStockOutbox extends Command
{
    protected $signature = 'channel:monitor-stock-outbox {--json : Cetak satu snapshot JSON untuk monitoring}';

    protected $description = 'Menampilkan antrean kerja push stok yang tahan restart beserta error asli terakhir.';

    public function __construct(
        private readonly ChannelStockSyncOutboxMonitorService $service,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $snapshot = $this->service->snapshot();
        $summary = $snapshot['summary'];
        $errors = $snapshot['errors'];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode([
                'generated_at' => $snapshot['generated_at'],
                'summary' => $summary,
                'errors' => $errors,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('STATUS PUSH STOK TERKINI');
        $this->table(
            ['Channel', 'Status', 'Jumlah listing', 'Jadwal berikutnya'],
            $summary->map(static fn (object $row): array => [
                $row->channel,
                $row->status,
                $row->total,
                $row->earliest_next_attempt ?? '-',
            ])->all(),
        );

        if ($errors->isNotEmpty()) {
            $this->newLine();
            $this->warn('ERROR ASLI TERAKHIR (maks. 20 kelompok)');
            $this->table(
                ['Channel', 'Status', 'Jumlah', 'Terakhir', 'Alasan'],
                $errors->map(static fn (object $row): array => [
                    $row->channel,
                    $row->status,
                    $row->total,
                    $row->last_seen_at,
                    $row->last_error,
                ])->all(),
            );
        }

        return self::SUCCESS;
    }
}
