<?php

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Services\ChannelLiveMappingVerifier;
use Modules\Channel\Support\ChannelVariantMappingResolver;
use Modules\Product\Models\ProductChannelMapping;

final class AuditLiveChannelMappings extends Command
{
    protected $signature = 'channel:audit-live-mappings
                            {--channel= : Batasi ke shopee, tiktok, atau lazada}
                            {--shop= : Batasi ke shop_id marketplace}
                            {--limit=100 : Maksimum listing yang diperiksa, 1 sampai 500}
                            {--include-ok : Tampilkan listing yang cocok}
                            {--json : Keluarkan JSON ringkas}';

    protected $description = 'Bandingkan model ID dan seller SKU mapping lokal dengan listing live tanpa mengirim stok';

    public function handle(ChannelLiveMappingVerifier $verifier): int
    {
        $limit = (int) $this->option('limit');
        if ($limit < 1 || $limit > 500) {
            $this->error('--limit harus antara 1 dan 500 listing.');

            return self::FAILURE;
        }

        $channel = strtolower(trim((string) $this->option('channel')));
        if ($channel !== '' && ! in_array($channel, ['shopee', 'tiktok', 'lazada'], true)) {
            $this->error('--channel harus shopee, tiktok, atau lazada.');

            return self::FAILURE;
        }

        $listings = ProductChannelMapping::query()
            ->whereNotNull('external_product_id')
            ->whereHas('channelShop', function ($query) use ($channel): void {
                $query->where('is_active', true)
                    ->whereHas('channel', function ($channelQuery) use ($channel): void {
                        $channelQuery->whereIn('code', $channel === '' ? ['shopee', 'tiktok', 'lazada'] : [$channel]);
                    });
            })
            ->with([
                'channelShop.channel',
                'variantMappings.variant.product',
            ])
            ->orderBy('channel_shop_id')
            ->orderBy('external_product_id')
            ->limit($limit)
            ->get();

        $rows = [];
        foreach ($listings as $listing) {
            $shop = $listing->channelShop;
            $channelCode = strtolower((string) ($shop?->channel?->code ?? ''));

            if (! $shop || $channelCode === '') {
                continue;
            }

            $mappings = ChannelVariantMappingResolver::enabledForListing($listing);
            $localError = ChannelVariantMappingResolver::stockPayloadError(
                $mappings,
                'external_sku_id',
                'Model ID',
                $channelCode === 'shopee',
            ) ?? ChannelVariantMappingResolver::sellerSkuPayloadError($mappings, 'Seller SKU');

            if ($localError !== null) {
                $status = 'LOCAL_INVALID';
                $reason = $localError;
                $issues = [];
            } else {
                $inspection = $verifier->inspect(
                    $channelCode,
                    $shop,
                    (string) $listing->external_product_id,
                    $mappings,
                );
                $status = strtoupper($inspection['status']);
                $reason = match ($inspection['status']) {
                    'ok' => null,
                    'unreadable' => 'Listing live tidak dapat dibaca; push harus diblok.',
                    default => 'Model ID atau seller SKU live tidak cocok dengan mapping lokal.',
                };
                $issues = $inspection['issues'];
            }

            if ($status === 'OK' && ! $this->option('include-ok')) {
                continue;
            }

            $rows[] = [
                'channel' => $channelCode,
                'shop_id' => (string) $shop->shop_id,
                'shop_name' => (string) $shop->shop_name,
                'listing_id' => (string) $listing->external_product_id,
                'mapping_id' => (string) $listing->id,
                'status' => $status,
                'reason' => $reason,
                'issues' => $issues,
            ];
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'examined_listings' => $listings->count(),
                'findings' => $rows,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('Listing diperiksa: '.$listings->count());
            $this->line('Temuan: '.count($rows));

            foreach ($rows as $row) {
                $this->line(sprintf(
                    '%s | %s | listing=%s | %s%s',
                    $row['channel'],
                    $row['shop_name'],
                    $row['listing_id'],
                    $row['status'],
                    $row['reason'] !== null ? ' | '.$row['reason'] : '',
                ));
            }
        }

        return $rows === [] ? self::SUCCESS : self::FAILURE;
    }
}
