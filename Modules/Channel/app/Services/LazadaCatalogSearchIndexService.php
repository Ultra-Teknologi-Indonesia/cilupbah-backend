<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LazadaCatalogSearchIndexService
{
    private const TABLE = 'channel_catalog_sku_indexes';

    public function upsertProducts(string $channelShopId, array $products, ?\DateTimeInterface $seenAt = null): int
    {
        $seenAt ??= now();
        $rows = [];

        foreach ($products as $product) {
            $externalProductId = trim((string) ($product['item_id'] ?? ''));
            if ($externalProductId === '') {
                continue;
            }

            $status = strtolower(trim((string) ($product['status'] ?? '')));
            if ($status !== '' && ! in_array($status, ['active', 'live'], true)) {
                continue;
            }

            $productName = trim((string) ($product['attributes']['name'] ?? $product['name'] ?? '')) ?: null;

            foreach (($product['skus'] ?? []) as $sku) {
                $sellerSku = trim((string) ($sku['SellerSku'] ?? $sku['seller_sku'] ?? ''));
                if ($sellerSku === '') {
                    continue;
                }

                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'channel_shop_id' => $channelShopId,
                    'external_product_id' => $externalProductId,
                    'external_sku_id' => isset($sku['SkuId']) || isset($sku['sku_id'])
                        ? (string) ($sku['SkuId'] ?? $sku['sku_id'])
                        : null,
                    'seller_sku' => $sellerSku,
                    'normalized_seller_sku' => self::normalizeSku($sellerSku),
                    'product_name' => $productName,
                    'listing_status' => $status !== '' ? $status : 'active',
                    'last_seen_at' => $seenAt,
                    'created_at' => $seenAt,
                    'updated_at' => $seenAt,
                ];
            }
        }

        if ($rows === []) {
            return 0;
        }

        DB::table(self::TABLE)->upsert(
            $rows,
            ['channel_shop_id', 'external_product_id', 'normalized_seller_sku'],
            [
                'external_sku_id',
                'seller_sku',
                'product_name',
                'listing_status',
                'last_seen_at',
                'updated_at',
            ],
        );

        return count($rows);
    }

    public function findExact(string $channelShopId, string $sellerSku, int $limit = 20): Collection
    {
        return DB::table(self::TABLE)
            ->where('channel_shop_id', $channelShopId)
            ->where('normalized_seller_sku', self::normalizeSku($sellerSku))
            ->orderByDesc('last_seen_at')
            ->limit(max(1, min(100, $limit)))
            ->get();
    }

    public static function normalizeSku(string $sku): string
    {
        return mb_strtolower(trim($sku));
    }
}
