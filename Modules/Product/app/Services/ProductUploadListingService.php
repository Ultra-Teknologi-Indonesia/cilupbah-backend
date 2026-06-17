<?php

namespace Modules\Product\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ChannelListingValidator;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductSyncLog;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Repositories\ProductUploadListingRepository;

class ProductUploadListingService
{
    public const MESSAGE_MATCHED = 'Sesuai sama master';

    public function __construct(
        private ProductUploadListingRepository $repository,
        private ChannelListingValidator $validator,
    ) {}

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

    public function match(string $productId, array $storeIds): array
    {
        $product = Product::with('variants')->findOrFail($productId);
        $variants = $product->variants;

        $mappings = ProductChannelMapping::where('product_id', $productId)
            ->whereIn('channel_shop_id', $storeIds)
            ->with('variantMappings')
            ->get()
            ->keyBy('channel_shop_id');

        $shops = ChannelShop::with('channel')
            ->whereIn('id', $storeIds)
            ->get()
            ->keyBy('id');

        $rows = [];

        foreach ($storeIds as $storeId) {
            $mapping = $mappings->get($storeId);
            $channelGroupId = $mapping->external_product_id ?? null;
            $syncedVariantIds = $mapping
                ? $mapping->variantMappings->pluck('variant_id')->all()
                : [];

            // Penyebab "match tapi gagal upload": (1) ada log upload gagal,
            // (2) belum siap upload (multi-varian tanpa atribut variasi).
            $failedMessage = $this->latestFailedUploadMessage($productId, $storeId);
            $readinessMessage = $this->uploadReadinessIssue($product, $shops->get($storeId));

            if ($variants->isEmpty()) {
                [$matched, $message] = $this->evaluate($mapping, null, $syncedVariantIds, $failedMessage, $readinessMessage);
                $rows[] = ['store_id' => $storeId, 'channel_group_id' => $channelGroupId, 'message' => $message, 'matched' => $matched];
                continue;
            }

            foreach ($variants as $variant) {
                [$matched, $message] = $this->evaluate($mapping, $variant, $syncedVariantIds, $failedMessage, $readinessMessage);
                $rows[] = ['store_id' => $storeId, 'channel_group_id' => $channelGroupId, 'message' => $message, 'matched' => $matched];
            }
        }

        return $rows;
    }

    private function evaluate(
        ?ProductChannelMapping $mapping,
        ?ProductVariant $variant,
        array $syncedVariantIds,
        ?string $failedMessage = null,
        ?string $readinessMessage = null
    ): array {
        // Upload pernah gagal → tampilkan errornya, jangan laporkan "sesuai".
        if ($failedMessage !== null) {
            return [false, $failedMessage];
        }

        // Belum siap upload (struktur atribut variasi) → akan gagal kalau diupload.
        if ($readinessMessage !== null) {
            return [false, $readinessMessage];
        }

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

    /**
     * Pesan error bila upload terakhir produk ke toko ini berstatus gagal; null jika
     * tidak ada upload atau upload terakhir berhasil.
     */
    private function latestFailedUploadMessage(string $productId, string $storeId): ?string
    {
        $log = ProductSyncLog::query()
            ->where('product_id', $productId)
            ->where('channel_shop_id', $storeId)
            ->where('action', ProductSyncLog::ACTION_UPLOAD)
            ->latest()
            ->first();

        if ($log && $log->status === ProductSyncLog::STATUS_FAILED) {
            return $log->error_message ?: 'Upload terakhir gagal';
        }

        return null;
    }

    /**
     * Masalah kesiapan upload independen dari mapping (mis. multi-varian tanpa atribut
     * variasi pada TikTok). Null bila siap.
     */
    private function uploadReadinessIssue(Product $product, ?ChannelShop $shop): ?string
    {
        $channelCode = $shop?->channel?->code;

        if ($channelCode === 'tiktok' && $this->validator->lacksVariationAttributes($product)) {
            return 'Produk multi-varian tanpa atribut variasi — wajib diisi sebelum upload ke TikTok.';
        }

        return null;
    }
}
