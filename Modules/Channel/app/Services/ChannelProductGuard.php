<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChannelProductGuard
{
    public function isDownloaded(?string $sku, ?string $source = null, ?string $channelProductId = null): bool
    {
        if ($sku && DB::table('product_variants')->where('sku', $sku)->exists()) {
            return true;
        }

        if ($source && $channelProductId) {
            $exists = DB::table('product_channel_mappings as pcm')
                ->join('channel_shops as cs', 'cs.id', '=', 'pcm.channel_shop_id')
                ->join('channels as c', 'c.id', '=', 'cs.channel_id')
                ->whereRaw('LOWER(c.name) = ?', [strtolower($source)])
                ->where('pcm.external_product_id', (string) $channelProductId)
                ->exists();

            if ($exists) {
                return true;
            }
        }

        return false;
    }

    public function unknownItems(array $items, ?string $source = null): array
    {
        $missing = [];
        $seen = [];

        foreach ($items as $item) {
            $sku = $item['sku'] ?? null;
            $cpid = $item['channel_product_id'] ?? null;

            $key = ($cpid ?? '') . '|' . ($sku ?? '');
            if ($key === '|' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            if (! $this->isDownloaded($sku, $source, $cpid)) {
                $missing[] = ['sku' => $sku, 'channel_product_id' => $cpid];
            }
        }

        return $missing;
    }

    public function reject(string $entity, string $source, ?string $shopId, string $ref, array $missing, array $extra = []): void
    {
        Log::info('ChannelProductGuard: ingest ditolak, produk belum di-download', array_merge([
            'entity'       => $entity,
            'source'       => $source,
            'shop_id'      => $shopId,
            'reference'    => $ref,
            'missing_skus' => $missing,
        ], $extra));
    }
}
