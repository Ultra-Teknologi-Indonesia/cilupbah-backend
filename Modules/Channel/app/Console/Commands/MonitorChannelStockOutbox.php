<?php

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Models\ChannelStockSyncOutbox;

class MonitorChannelStockOutbox extends Command
{
    protected $signature = 'channel:monitor-stock-outbox {--json : Cetak satu snapshot JSON untuk monitoring}';

    protected $description = 'Menampilkan antrean kerja push stok yang tahan restart beserta error asli terakhir.';

    public function handle(): int
    {
        $summary = ChannelStockSyncOutbox::query()
            ->join('channel_shops', 'channel_shops.id', '=', 'channel_stock_sync_outbox.channel_shop_id')
            ->join('channels', 'channels.id', '=', 'channel_shops.channel_id')
            ->selectRaw("channels.code AS channel, channel_stock_sync_outbox.status, COUNT(*) AS total, MIN(channel_stock_sync_outbox.next_attempt_at) AS earliest_next_attempt")
            ->groupBy('channels.code', 'channel_stock_sync_outbox.status')
            ->orderBy('channels.code')
            ->orderBy('channel_stock_sync_outbox.status')
            ->get();

        $errors = ChannelStockSyncOutbox::query()
            ->join('channel_shops', 'channel_shops.id', '=', 'channel_stock_sync_outbox.channel_shop_id')
            ->join('channels', 'channels.id', '=', 'channel_shops.channel_id')
            ->whereIn('channel_stock_sync_outbox.status', [
                ChannelStockSyncOutbox::STATUS_FAILED,
                ChannelStockSyncOutbox::STATUS_PENDING,
            ])
            ->whereNotNull('channel_stock_sync_outbox.last_error')
            ->where('channel_stock_sync_outbox.last_error', '!=', '')
            ->selectRaw('channels.code AS channel, channel_stock_sync_outbox.status, channel_stock_sync_outbox.last_error, COUNT(*) AS total, MAX(channel_stock_sync_outbox.updated_at) AS last_seen_at')
            ->groupBy('channels.code', 'channel_stock_sync_outbox.status', 'channel_stock_sync_outbox.last_error')
            ->orderByDesc('total')
            ->limit(20)
            ->get();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode([
                'generated_at' => now()->toIso8601String(),
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
