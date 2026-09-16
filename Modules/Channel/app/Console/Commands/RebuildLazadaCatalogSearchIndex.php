<?php

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\LazadaProductService;

class RebuildLazadaCatalogSearchIndex extends Command
{
    protected $signature = 'channel:lazada-rebuild-search-index
        {--shop-id=* : External Lazada shop ID; omit to rebuild every active Lazada shop}';

    protected $description = 'Rebuild the verified Lazada seller SKU search index without importing products';

    public function handle(LazadaProductService $service): int
    {
        $requestedShopIds = array_values(array_unique(array_filter(array_map(
            'strval',
            (array) $this->option('shop-id'),
        ))));

        $shops = ChannelShop::query()
            ->whereHas('channel', fn ($query) => $query->where('code', 'lazada'))
            ->where('is_active', true)
            ->whereNull('disconnected_at')
            ->when($requestedShopIds !== [], fn ($query) => $query->whereIn('shop_id', $requestedShopIds))
            ->orderBy('shop_id')
            ->get(['shop_id', 'shop_name']);

        if ($shops->isEmpty()) {
            $this->warn('Tidak ada toko Lazada aktif dan terhubung yang cocok.');

            return self::SUCCESS;
        }

        foreach ($shops as $shop) {
            $this->line("Membangun indeks SKU: {$shop->shop_id} ({$shop->shop_name})");

            try {
                $result = $service->rebuildSearchIndex((string) $shop->shop_id);
            } catch (\Throwable $e) {
                $this->error("Gagal {$shop->shop_id}: {$e->getMessage()}");

                continue;
            }

            $this->info(sprintf(
                'Selesai: %d listing dipindai, %d SKU diindeks, %d halaman%s.',
                $result['products_scanned'],
                $result['skus_indexed'],
                $result['pages'],
                $result['complete'] ? '' : ' (belum lengkap; batas halaman tercapai)',
            ));
        }

        return self::SUCCESS;
    }
}
