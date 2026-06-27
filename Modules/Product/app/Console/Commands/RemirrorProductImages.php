<?php

namespace Modules\Product\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Services\ChannelDownloadService;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductMedia;
use Modules\Product\Support\InternalMediaUrl;

class RemirrorProductImages extends Command
{
    protected $signature = 'products:remirror-images
        {--channel= : Batasi ke channel tertentu (tiktok/shopee/lazada)}
        {--limit=0 : Batasi jumlah produk yang di-repull (0 = semua)}';

    protected $description = 'Re-pull produk yang gambarnya masih di CDN eksternal dari channel-nya, lalu mirror ke CDN internal';

    public function handle(ChannelDownloadService $downloads): int
    {
        $host = InternalMediaUrl::host();
        if (! $host) {
            $this->error('Host CDN internal tidak terkonfigurasi (filesystems.disks.s3.url / APP_URL).');

            return self::FAILURE;
        }

        $channelFilter = $this->option('channel');
        $limit = max(0, (int) $this->option('limit'));

        $productIds = ProductMedia::query()
            ->whereNotNull('url')
            ->where('url', 'not like', "%{$host}%")
            ->distinct()
            ->pluck('product_id');

        if ($productIds->isEmpty()) {
            $this->info('Tidak ada media eksternal — semua sudah di CDN internal.');

            return self::SUCCESS;
        }

        $mappings = ProductChannelMapping::query()
            ->whereIn('product_id', $productIds)
            ->whereNotNull('external_product_id')
            ->with('channelShop.channel')
            ->get();

        $this->info("{$productIds->count()} produk punya media eksternal; mengevaluasi {$mappings->count()} channel mapping...");

        $processed = 0;
        $ok = 0;
        $failed = 0;
        $skipped = 0;
        $seen = [];

        foreach ($mappings as $m) {
            $channel = $m->channelShop?->channel?->code;
            $shopId = $m->channelShop?->shop_id;
            $externalId = $m->external_product_id;

            if (! $channel || ! $shopId || ! $externalId) {
                $skipped++;
                continue;
            }

            if ($channelFilter && $channel !== $channelFilter) {
                continue;
            }

            $key = "{$channel}|{$shopId}|{$externalId}";
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            if ($limit > 0 && $processed >= $limit) {
                break;
            }
            $processed++;

            try {
                $downloads->downloadProduct($channel, $shopId, $externalId);
                $ok++;
                $this->line("  OK    {$channel} {$externalId}");
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("  GAGAL {$channel} {$externalId}: {$e->getMessage()}");
            }
        }

        $this->info("Selesai. Re-pull: {$processed}, sukses: {$ok}, gagal: {$failed}, mapping dilewati: {$skipped}.");
        $this->line('Mirroring sisa media servable berjalan di background (MirrorProductMediaJob).');

        return self::SUCCESS;
    }
}
