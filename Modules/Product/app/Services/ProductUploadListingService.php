<?php

namespace Modules\Product\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Repositories\ProductUploadListingRepository;

class ProductUploadListingService
{
    public const MESSAGE_MATCHED = 'Sesuai sama master';

    public function __construct(private ProductUploadListingRepository $repository) {}

    /**
     * Daftar toko tujuan untuk satu produk (tab Belum/Sudah Diupload). Data
     * produk + mapping disuntik ke tiap ChannelShop agar resource dapat membaca.
     */
    public function listDestinations(string $productId, bool $isUploaded): LengthAwarePaginator
    {
        $product = Product::findOrFail($productId);

        $paginator = $this->repository->paginateDestinations($productId, $isUploaded);

        $mappings = $isUploaded
            ? ProductChannelMapping::where('product_id', $productId)
                ->whereIn('channel_shop_id', $paginator->getCollection()->pluck('id'))
                ->with('variantMappings.variant.options')
                ->get()
                ->keyBy('channel_shop_id')
            : collect();

        $paginator->getCollection()->transform(function (ChannelShop $shop) use ($product, $mappings) {
            $shop->setAttribute('item_group_id', $product->id);
            $shop->setAttribute('item_group_name', $product->name);
            $shop->setRelation('productMapping', $mappings->get($shop->id));

            return $shop;
        });

        return $paginator;
    }

    /**
     * Kecocokan data master dengan channel per (toko × varian).
     *
     * Catatan: BE tidak menyimpan nama produk sisi-channel, jadi kecocokan
     * dinilai dari drift sinkronisasi nyata (varian master belum tersinkron,
     * atau mapping rejected/failed) — bukan perbandingan nama seperti Jubelio.
     *
     * @param  string[]  $storeIds  channel_shop UUID
     * @return array<int, array{store_id:string, channel_group_id:?string, message:string, matched:bool}>
     */
    public function match(string $productId, array $storeIds): array
    {
        $product = Product::with('variants')->findOrFail($productId);
        $variants = $product->variants;

        $mappings = ProductChannelMapping::where('product_id', $productId)
            ->whereIn('channel_shop_id', $storeIds)
            ->with('variantMappings')
            ->get()
            ->keyBy('channel_shop_id');

        $rows = [];

        foreach ($storeIds as $storeId) {
            $mapping = $mappings->get($storeId);
            $channelGroupId = $mapping->external_product_id ?? null;
            $syncedVariantIds = $mapping
                ? $mapping->variantMappings->pluck('variant_id')->all()
                : [];

            if ($variants->isEmpty()) {
                [$matched, $message] = $this->evaluate($mapping, null, $syncedVariantIds);
                $rows[] = ['store_id' => $storeId, 'channel_group_id' => $channelGroupId, 'message' => $message, 'matched' => $matched];
                continue;
            }

            foreach ($variants as $variant) {
                [$matched, $message] = $this->evaluate($mapping, $variant, $syncedVariantIds);
                $rows[] = ['store_id' => $storeId, 'channel_group_id' => $channelGroupId, 'message' => $message, 'matched' => $matched];
            }
        }

        return $rows;
    }

    /**
     * @param  string[]  $syncedVariantIds
     * @return array{0:bool,1:string}
     */
    private function evaluate(?ProductChannelMapping $mapping, ?ProductVariant $variant, array $syncedVariantIds): array
    {
        if (! $mapping) {
            return [true, self::MESSAGE_MATCHED];
        }

        if (in_array($mapping->sync_status, [ProductChannelMapping::STATUS_REJECTED, ProductChannelMapping::STATUS_FAILED], true)) {
            return [false, $mapping->error_message ?: 'Sinkronisasi gagal'];
        }

        if ($variant && ! in_array($variant->id, $syncedVariantIds, true)) {
            return [false, 'Varian belum tersinkron ke channel'];
        }

        return [true, self::MESSAGE_MATCHED];
    }
}
