<?php

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Models\SalesOrder;

class ShadowPurgeCommand extends Command
{
    protected $signature = 'channel:shadow-purge
        {--shop= : Batasi ke satu shop_id marketplace}
        {--before= : Hanya order dengan transaction_date sebelum tanggal ini (WIB)}
        {--force : Benar-benar hapus. Tanpa ini command hanya menampilkan rencana}';

    protected $description = 'Hapus arsip order shadow setelah migrasi selesai.';

    private const TIMEZONE = 'Asia/Jakarta';

    public function handle(): int
    {
        $query = SalesOrder::query()
            ->onlyShadow()
            ->when($this->option('shop'), fn ($q, $shopId) => $q->where('channel_shop_id', $shopId))
            ->when($this->option('before'), fn ($q, $before) => $q->where(
                'transaction_date',
                '<',
                Carbon::parse($before, self::TIMEZONE)->startOfDay()->utc(),
            ));

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('Tidak ada order shadow yang cocok.');

            return self::SUCCESS;
        }

        $byShop = (clone $query)
            ->selectRaw('channel_shop_id, source, COUNT(*) as total')
            ->groupBy('channel_shop_id', 'source')
            ->get()
            ->map(fn ($row) => [$row->channel_shop_id, $row->source, $row->total])
            ->all();

        $this->table(['Shop ID', 'Channel', 'Order'], $byShop);

        if (! $this->option('force')) {
            $this->warn("Rencana: {$total} order shadow akan dihapus. Jalankan ulang dengan --force untuk benar-benar menghapus.");

            return self::SUCCESS;
        }

        if (! $this->confirm("Hapus permanen {$total} order shadow?")) {
            $this->info('Dibatalkan.');

            return self::SUCCESS;
        }

        $deleted = 0;
        $skipped = [];

        foreach ($query->select('id', 'salesorder_no')->cursor() as $order) {
            try {
                DB::transaction(fn () => $order->delete());
                $deleted++;
            } catch (\Throwable $e) {
                $skipped[] = [$order->salesorder_no, mb_substr($e->getMessage(), 0, 120)];
            }
        }

        $this->info("{$deleted} order shadow dihapus.");

        if ($skipped !== []) {
            $this->warn(count($skipped) . ' order tidak bisa dihapus karena masih punya data turunan:');
            $this->table(['No. Pesanan', 'Alasan'], $skipped);
        }

        return self::SUCCESS;
    }
}
