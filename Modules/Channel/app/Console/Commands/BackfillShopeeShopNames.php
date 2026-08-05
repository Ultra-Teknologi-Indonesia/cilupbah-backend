<?php

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Services\ShopeeAuthService;

/**
 * Ambil nama toko asli dari Shopee (get_shop_info) untuk toko yang sudah terhubung
 * tapi masih memakai nama fallback "Shopee <shop_id>". Idempoten & aman diulang.
 */
class BackfillShopeeShopNames extends Command
{
    protected $signature = 'shopee:backfill-shop-names
        {--all : Perbarui semua toko, bukan hanya yang masih memakai nama fallback}';

    protected $description = 'Ambil nama toko asli dari Shopee (get_shop_info) untuk toko terhubung.';

    public function handle(ShopeeAuthService $service, ChannelShopRepository $shopRepository): int
    {
        $shops = $shopRepository->getShopsByChannelCode('shopee');

        $updated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($shops as $shop) {
            if ($shop->disconnected_at !== null || ! $shop->access_token) {
                $skipped++;
                continue;
            }

            $isFallback = $shop->shop_name === null || $shop->shop_name === ('Shopee ' . $shop->shop_id);
            if (! $this->option('all') && ! $isFallback) {
                $skipped++;
                continue;
            }

            if ($service->syncShopName($shop)) {
                $this->line("  {$shop->shop_id} -> " . ($shop->fresh()->shop_name ?? '?'));
                $updated++;
            } else {
                $this->warn("  {$shop->shop_id} gagal (token kedaluwarsa / API error) — cek log.");
                $failed++;
            }
        }

        $this->info("Selesai. Diperbarui: {$updated}, dilewati: {$skipped}, gagal: {$failed}.");

        return self::SUCCESS;
    }
}
