<?php

namespace Modules\Product\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ChannelListingValidator;
use Modules\Product\Models\ChannelCategory;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelDraft;
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
        $rulesSummaryCache = [];

        foreach ($storeIds as $storeId) {
            $mapping = $mappings->get($storeId);
            $channelGroupId = $mapping->external_product_id ?? null;
            $syncedVariantIds = $mapping
                ? $mapping->variantMappings->pluck('variant_id')->all()
                : [];

            $failedMessage = $this->latestFailedUploadMessage($productId, $storeId);
            $readinessMessage = $this->uploadReadinessIssue($product, $shops->get($storeId));

            $shop = $shops->get($storeId);
            $rulesSummary = null;
            if ($shop && ($shop->channel->code ?? '') === 'tiktok') {
                $rulesSummary = $rulesSummaryCache[$storeId] ??= $this->buildRulesSummary($product, $shop);
            }

            if ($variants->isEmpty()) {
                [$matched, $message] = $this->evaluate($mapping, null, $syncedVariantIds, $failedMessage, $readinessMessage);
                $rows[] = ['store_id' => $storeId, 'channel_group_id' => $channelGroupId, 'message' => $message, 'matched' => $matched, 'rules_summary' => $rulesSummary];
                continue;
            }

            foreach ($variants as $variant) {
                [$matched, $message] = $this->evaluate($mapping, $variant, $syncedVariantIds, $failedMessage, $readinessMessage);
                $rows[] = ['store_id' => $storeId, 'channel_group_id' => $channelGroupId, 'message' => $message, 'matched' => $matched, 'rules_summary' => $rulesSummary];
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

        if ($failedMessage !== null) {
            return [false, $failedMessage];
        }

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

    private function uploadReadinessIssue(Product $product, ?ChannelShop $shop): ?string
    {
        $channelCode = $shop?->channel?->code;

        if ($channelCode === 'tiktok' && $this->validator->lacksVariationAttributes($product)) {
            return 'Produk multi-varian tanpa atribut variasi — wajib diisi sebelum upload ke TikTok.';
        }

        return null;
    }

    private function buildRulesSummary(Product $product, ChannelShop $shop): ?array
    {
        $channelCategory = $this->resolveChannelCategory($product, $shop);
        if (! $channelCategory || ! $channelCategory->rules) {
            return null;
        }

        $rules = $channelCategory->rules;
        $certs = $rules['product_certifications'] ?? [];
        $requiredCerts = array_filter($certs, fn ($c) => $c['is_required'] ?? false);

        $hasSpecial = count($requiredCerts) > 0
            || ($rules['size_chart']['is_required'] ?? false)
            || ($rules['manufacturer']['is_required'] ?? false)
            || ($rules['package_dimension']['is_required'] ?? false);

        return [
            'required_certs_count' => count($requiredCerts),
            'size_chart_required' => $rules['size_chart']['is_required'] ?? false,
            'has_special_requirements' => $hasSpecial,
        ];
    }

    private function resolveChannelCategory(Product $product, ChannelShop $shop): ?ChannelCategory
    {
        $draft = ProductChannelDraft::where('product_id', $product->id)
            ->where('channel_shop_id', $shop->id)
            ->latest('updated_at')
            ->first();

        if ($draft && $draft->channel_category_id) {
            return ChannelCategory::find($draft->channel_category_id);
        }

        if (! $product->category_id) {
            return null;
        }

        return ChannelCategory::query()
            ->where('channel_id', $shop->channel_id)
            ->whereHas('localCategories', fn ($q) => $q->where('categories.id', $product->category_id))
            ->first();
    }
}
