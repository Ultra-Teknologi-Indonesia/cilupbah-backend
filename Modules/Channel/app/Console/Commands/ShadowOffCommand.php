<?php

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\ChannelShop;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\SalesOrderService;

class ShadowOffCommand extends Command
{
    protected $signature = 'channel:shadow-off
        {--shop= : Batasi ke satu shop_id marketplace}
        {--promote : Promosikan order shadow yang masih terbuka jadi order sungguhan}
        {--force : Jalankan tanpa konfirmasi}
        {--dry-run : Tampilkan dampaknya lalu rollback}';

    protected $description = 'Matikan Shadow Mode per toko saat cutover, dengan opsi mempromosikan order yang masih terbuka.';

    public function handle(SalesOrderService $orderService): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $promote = (bool) $this->option('promote');

        $shops = ChannelShop::with('channel')
            ->where('is_shadow_mode', true)
            ->when($this->option('shop'), fn ($query, $shopId) => $query->where('shop_id', $shopId))
            ->get();

        if ($shops->isEmpty()) {
            $this->info('Tidak ada toko dengan Shadow Mode aktif.');

            return self::SUCCESS;
        }

        $preview = $shops->map(fn (ChannelShop $shop) => [
            $shop->shop_name,
            $shop->channel->code ?? '-',
            $this->openShadowOrders($shop)->count(),
            $this->shadowOrders($shop)->count(),
        ])->all();

        $this->table(['Toko', 'Channel', 'Order terbuka', 'Total order shadow'], $preview);

        if (! $promote) {
            $this->warn('Tanpa --promote, order shadow yang masih terbuka tetap jadi arsip dan tidak akan difulfill di sistem ini.');
        }

        if (! $isDryRun && ! $this->option('force') && ! $this->confirm('Matikan Shadow Mode untuk toko di atas?')) {
            $this->info('Dibatalkan.');

            return self::SUCCESS;
        }

        $rows = [];

        if ($isDryRun) {
            DB::beginTransaction();
        }

        try {
            foreach ($shops as $shop) {
                $promoted = 0;
                $skipped = 0;

                if ($promote) {
                    foreach ($this->openShadowOrders($shop)->cursor() as $order) {
                        if ($orderService->promoteFromShadow($order)) {
                            $promoted++;
                        } else {
                            $skipped++;
                        }
                    }
                }

                $shop->forceFill(['is_shadow_mode' => false])->save();

                $rows[] = [
                    $shop->shop_name,
                    $promoted,
                    $skipped,
                    $this->shadowOrders($shop)->count(),
                ];
            }
        } finally {
            if ($isDryRun) {
                DB::rollBack();
                $this->warn('DRY RUN: semua perubahan di atas sudah di-rollback.');
            }
        }

        $this->table(['Toko', 'Dipromosikan', 'Dilewati', 'Tersisa jadi arsip'], $rows);

        if (! $isDryRun) {
            $this->info('Shadow Mode dimatikan. Order arsip tetap tersimpan dan bisa dilihat lewat filter[shadow]=only.');
            $this->line('Untuk menghapus arsipnya kalau sudah tidak diperlukan: php artisan channel:shadow-purge');
        }

        return self::SUCCESS;
    }

    private function shadowOrders(ChannelShop $shop)
    {
        return SalesOrder::query()
            ->onlyShadow()
            ->where('channel_shop_id', $shop->shop_id);
    }

    private function openShadowOrders(ChannelShop $shop)
    {
        return $this->shadowOrders($shop)
            ->where('is_canceled', false)
            ->whereIn('status', ['pending', 'reserved', 'UNPAID', 'AWAITING_BUYER_CONFIRMATION']);
    }
}
