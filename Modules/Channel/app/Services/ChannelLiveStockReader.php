<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Log;

/**
 * Membaca stok yang sedang tayang di listing marketplace.
 *
 * Angka inilah yang dilihat pembeli dan yang menentukan oversell — bukan angka
 * di WMS, bukan angka di sistem lama. Dipakai rekonsiliasi stok untuk
 * membandingkan tiga sisi sebelum serah terima.
 *
 * @return array<string, int> external_sku_id => qty
 */
class ChannelLiveStockReader
{
    public function read(string $channelCode, string $shopId, string $externalProductId): array
    {
        try {
            return match (strtolower($channelCode)) {
                'shopee' => $this->readShopee($shopId, $externalProductId),
                'tiktok' => $this->readTikTok($shopId, $externalProductId),
                'lazada' => $this->readLazada($shopId, $externalProductId),
                default  => [],
            };
        } catch (\Throwable $e) {
            Log::warning('Gagal membaca stok live listing.', [
                'channel'             => $channelCode,
                'shop_id'             => $shopId,
                'external_product_id' => $externalProductId,
                'error'               => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function readShopee(string $shopId, string $externalProductId): array
    {
        $data = app(ShopeeProductService::class)->getModelList($shopId, $externalProductId);
        $result = [];

        foreach ($data['models'] ?? [] as $model) {
            $modelId = (string) ($model['model_id'] ?? '');

            if ($modelId === '') {
                continue;
            }

            $result[$modelId] = $this->firstNumeric([
                data_get($model, 'stock_info_v2.seller_stock.0.stock'),
                data_get($model, 'stock_info_v2.summary_info.total_available_stock'),
                data_get($model, 'stock_info.0.current_stock'),
            ]);
        }

        return $result;
    }

    private function readTikTok(string $shopId, string $externalProductId): array
    {
        $detail = app(TikTokProductService::class)->fetchLiveProduct($shopId, $externalProductId);
        $result = [];

        foreach ($detail['skus'] ?? [] as $sku) {
            $skuId = (string) ($sku['id'] ?? '');

            if ($skuId === '') {
                continue;
            }

            $quantity = 0;
            foreach ($sku['inventory'] ?? [] as $inventory) {
                $quantity += (int) ($inventory['quantity'] ?? 0);
            }

            $result[$skuId] = $quantity;
        }

        return $result;
    }

    private function readLazada(string $shopId, string $externalProductId): array
    {
        $item = app(LazadaProductService::class)->fetchLiveProduct($shopId, $externalProductId);
        $result = [];

        foreach ($item['skus'] ?? [] as $sku) {
            $skuId = (string) ($sku['SkuId'] ?? $sku['sku_id'] ?? '');

            if ($skuId === '') {
                continue;
            }

            $result[$skuId] = $this->firstNumeric([
                $sku['SellableQuantity'] ?? null,
                $sku['quantity'] ?? null,
                $sku['Quantity'] ?? null,
            ]);
        }

        return $result;
    }

    private function firstNumeric(array $candidates): int
    {
        foreach ($candidates as $value) {
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return 0;
    }
}
