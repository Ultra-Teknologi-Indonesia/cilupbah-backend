<?php

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Models\DownloadTransaction;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;

class BackfillDownloadHistory extends Command
{
    protected $signature = 'channel:backfill-download-history
        {--dry-run : Hitung & tampilkan saja, tanpa menulis ke database}
        {--per-shop : Buat 1 transaksi ringkas per toko (default: 1 transaksi per produk)}
        {--shop= : Batasi ke satu toko (shop_id eksternal)}
        {--force : Tetap jalan meski riwayat sudah ada (berisiko duplikat)}';

    protected $description = 'Isi ulang riwayat download (DownloadTransaction) dari produk yang sudah ter-mapping ke channel. Pelaku = system, waktu diambil dari created_at mapping.';

    public function handle(ChannelShopRepository $shopRepository): int
    {
        $perShop = (bool) $this->option('per-shop');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $shopFilterId = null;
        if ($shopExternal = $this->option('shop')) {
            $shopFilterId = $shopRepository->getIdByShopId((string) $shopExternal);
            if (! $shopFilterId) {
                $this->error("Toko '{$shopExternal}' tidak ditemukan.");

                return self::FAILURE;
            }
        }

        $base = ProductChannelMapping::query()
            ->whereHas('product', fn ($q) => $q->whereIn('status', [Product::STATUS_MASTER, Product::STATUS_DOWNLOAD]))
            ->when($shopFilterId, fn ($q) => $q->where('channel_shop_id', $shopFilterId));

        $sourceCount = (clone $base)->count();
        if ($sourceCount === 0) {
            $this->warn('Tidak ada mapping produk yang memenuhi syarat. Tidak ada yang di-backfill.');

            return self::SUCCESS;
        }

        $existing = DownloadTransaction::query()
            ->when($shopFilterId, fn ($q) => $q->where('channel_shop_id', $shopFilterId))
            ->count();

        if ($existing > 0 && ! $force && ! $dryRun) {
            $this->error("Sudah ada {$existing} transaksi download pada scope ini. Batal untuk mencegah duplikat.");
            $this->line('Gunakan --dry-run untuk melihat rencana, atau --force bila memang ingin menambah lagi.');

            return self::FAILURE;
        }

        $mode = $perShop ? 'per-toko (ringkas)' : 'per-produk (detail)';
        $this->info("Backfill riwayat download — mode {$mode}" . ($dryRun ? ' [DRY-RUN]' : ''));
        $this->line("Sumber: {$sourceCount} mapping produk (status master/download).");

        return $perShop
            ? $this->runPerShop(clone $base, $dryRun)
            : $this->runPerProduct(clone $base, $dryRun);
    }

    private function runPerShop($base, bool $dryRun): int
    {
        $rows = $base
            ->selectRaw('channel_shop_id, COUNT(*) AS c, MAX(created_at) AS last_at')
            ->groupBy('channel_shop_id')
            ->get();

        $created = 0;
        foreach ($rows as $row) {
            $this->line("  toko {$row->channel_shop_id}: {$row->c} produk (terakhir {$row->last_at})");

            if ($dryRun) {
                continue;
            }

            $trx = DownloadTransaction::create([
                'channel_shop_id' => $row->channel_shop_id,
                'executed_by' => null,
                'state' => DownloadTransaction::STATE_DONE,
                'all_product' => (int) $row->c,
                'total_downloaded' => (int) $row->c,
                'total_failed' => 0,
                'progress_percent' => 100,
            ]);
            $this->stampCreatedAt($trx, $row->last_at);
            $created++;
        }

        $this->info($dryRun
            ? "Akan dibuat {$rows->count()} transaksi (1 per toko)."
            : "Selesai. {$created} transaksi dibuat (1 per toko).");

        return self::SUCCESS;
    }

    private function runPerProduct($base, bool $dryRun): int
    {
        if ($dryRun) {
            $this->info("Akan dibuat {$base->count()} transaksi (1 per produk).");

            return self::SUCCESS;
        }

        $created = 0;
        $base->select(['id', 'channel_shop_id', 'created_at'])
            ->orderBy('created_at')
            ->chunkById(500, function ($mappings) use (&$created) {
                foreach ($mappings as $mapping) {
                    $trx = DownloadTransaction::create([
                        'channel_shop_id' => $mapping->channel_shop_id,
                        'executed_by' => null,
                        'state' => DownloadTransaction::STATE_DONE,
                        'all_product' => 1,
                        'total_downloaded' => 1,
                        'total_failed' => 0,
                        'progress_percent' => 100,
                    ]);
                    $this->stampCreatedAt($trx, $mapping->created_at);
                    $created++;
                }
            });

        $this->info("Selesai. {$created} transaksi dibuat (1 per produk).");

        return self::SUCCESS;
    }

    private function stampCreatedAt(DownloadTransaction $trx, $at): void
    {
        if (! $at) {
            return;
        }

        $trx->timestamps = false;
        $trx->created_at = $at;
        $trx->updated_at = $at;
        $trx->save();
        $trx->timestamps = true;
    }
}
