<?php

namespace Modules\Product\Services;

use App\Support\FriendlyError;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Jobs\SyncProductToChannelJob;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Repositories\ChannelProductRepository;
use Modules\Channel\Services\ChannelListingValidator;
use Modules\Channel\Services\ChannelSyncSettingService;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelDraft;
use Modules\Product\Models\ProductSyncLog;
use Modules\Product\Repositories\ProductChannelDraftRepository;
use Modules\Product\Repositories\ProductRepository;
use RuntimeException;

class ProductChannelDraftService
{
    public function __construct(
        private ChannelListingValidator $validator,
        private ProductChannelDraftRepository $draftRepository,
        private ProductRepository $productRepository,
        private ChannelProductRepository $channelProductRepository,
        private ChannelSyncSettingService $syncSettingService,
    ) {}

    public function requiredAttributes(string $productId, string $shopId): array
    {
        $channelShop = $this->draftRepository->findShopByShopId($shopId);
        if (! $channelShop) {
            throw new RuntimeException('Toko tidak ditemukan', 404);
        }

        if (($channelShop->channel->code ?? '') !== 'tiktok') {
            return [
                'data' => ['channel_category_id' => null, 'attributes' => []],
                'message' => 'Non-TikTok channel',
            ];
        }

        $product = $this->productRepository->findWithRelations($productId);
        if (! $product) {
            throw new RuntimeException('Produk tidak ditemukan', 404);
        }

        $channelCategory = $this->resolveChannelCategoryForProduct($product, $channelShop);
        if (! $channelCategory) {
            throw new RuntimeException('Kategori belum dipetakan ke TikTok. Petakan kategori terlebih dahulu.', 422);
        }

        $requiredAttrs = $this->draftRepository->requiredChannelAttributes($channelCategory->id);

        $coveredExternalIds = $this->getCoveredAttributeExternalIds($product->id, $channelCategory->id);

        $attributes = $requiredAttrs->map(function ($attr) use ($coveredExternalIds) {
            $isCovered = in_array($attr->external_id, $coveredExternalIds);

            return [
                'external_id' => $attr->external_id,
                'name' => $attr->name,
                'is_covered' => $isCovered,
                'options' => $attr->options->map(fn ($opt) => [
                    'external_id' => $opt->external_id,
                    'name' => $opt->name,
                ])->values()->all(),
            ];
        })->values()->all();

        return [
            'data' => [
                'channel_category_id' => $channelCategory->id,
                'attributes' => $attributes,
                'rules' => $channelCategory->rules,
            ],
            'message' => null,
        ];
    }

    private function resolveChannelCategoryForProduct($product, ChannelShop $shop): ?object
    {
        $draft = $this->draftRepository->latestDraftForProductShop($product->id, $shop->id);

        if ($draft && $draft->channel_category_id) {
            $info = $this->channelProductRepository->getChannelCategoryInfoById($draft->channel_category_id);
            if ($info) {
                return $info;
            }
        }

        if (! empty($product->category_id)) {
            return $this->channelProductRepository->getChannelCategoryInfoByInternal($product->category_id, $shop->channel_id);
        }

        return null;
    }

    private function getCoveredAttributeExternalIds(string $productId, string $channelCategoryId): array
    {
        $specs = $this->channelProductRepository->getProductSpecifications($productId);
        $covered = [];

        foreach ($specs as $spec) {
            $mapping = $this->channelProductRepository->getAttributeChannelMapping($spec->attribute_id);
            if (! $mapping) {
                continue;
            }

            $channelAttr = $this->channelProductRepository->getChannelAttribute($mapping->channel_attribute_id);
            if ($channelAttr && $channelAttr->channel_category_id === $channelCategoryId) {
                $covered[] = $channelAttr->external_id;
            }
        }

        return $covered;
    }

    public function upsertDraft(string $productId, string $shopId, array $data, ?string $userId = null): ProductChannelDraft
    {
        $channelShopId = $this->requireChannelShopId($shopId);

        return ProductChannelDraft::updateOrCreate(
            [
                'product_id' => $productId,
                'channel_shop_id' => $channelShopId,
            ],
            array_filter([
                'channel_category_id' => $data['channel_category_id'] ?? null,
                'attribute_mapping' => $data['attribute_mapping'] ?? null,
                'price_override' => $data['price_override'] ?? null,
                'status' => $data['status'] ?? ProductChannelDraft::STATUS_DRAFT,
                'created_by' => $userId,
            ], fn ($value) => $value !== null)
        );
    }

    public function updateDraft(ProductChannelDraft $draft, array $data): ProductChannelDraft
    {
        $draft->update($data);

        return $draft->fresh('channelShop');
    }

    public function deleteDraft(ProductChannelDraft $draft): void
    {
        $draft->delete();
    }

    public function uploadDraft(string $draftId): ProductSyncLog
    {
        return DB::transaction(function () use ($draftId) {
            $draft = ProductChannelDraft::findOrFail($draftId);

            if ($draft->status === ProductChannelDraft::STATUS_CANCELLED) {
                throw new DomainException('Draft yang dibatalkan tidak dapat di-upload.');
            }

            $this->assertReadyForUpload($draft);
            $this->assertCatalogUploadEnabled($draft);

            $log = ProductSyncLog::record([
                'product_id' => $draft->product_id,
                'channel_shop_id' => $draft->channel_shop_id,
                'action' => ProductSyncLog::ACTION_UPLOAD,
                'status' => ProductSyncLog::STATUS_PENDING,
            ]);

            $attributeMapping = $draft->attribute_mapping;

            SyncProductToChannelJob::dispatch(
                $draft->product_id,
                $draft->channel_shop_id,
                'push',
                $attributeMapping,
                $draft->id,
                $log->id,
            )->afterCommit();

            return $log;
        });
    }

    public function bulkUpload(array $draftIds): array
    {
        $uploaded = 0;
        $skipped = [];

        foreach ($draftIds as $id) {
            try {
                $this->uploadDraft($id);
                $uploaded++;
            } catch (\Throwable $e) {
                $skipped[] = ['id' => $id, 'reason' => FriendlyError::generic($e->getMessage(), 'Draft tidak dapat di-upload.')];
            }
        }

        return ['uploaded' => $uploaded, 'skipped' => $skipped];
    }

    private function assertReadyForUpload(ProductChannelDraft $draft): void
    {
        $product = Product::find($draft->product_id);
        if (! $product) {
            throw new DomainException('Produk tidak ditemukan.');
        }

        if ($this->validator->lacksVariationAttributes($product)) {
            $channelCode = ChannelShop::with('channel')->find($draft->channel_shop_id)?->channel?->code ?? 'channel';
            throw new DomainException(
                "Produk multi-varian tanpa atribut variasi — wajib diisi sebelum upload ke {$channelCode}."
            );
        }
    }

    private function assertCatalogUploadEnabled(ProductChannelDraft $draft): void
    {
        if ($this->syncSettingService->isPaused()) {
            throw new DomainException('Sinkronisasi channel sedang dinonaktifkan. Upload belum dapat diproses.');
        }

        $shop = ChannelShop::with('channel')->find($draft->channel_shop_id);
        if (! $shop || ! $shop->catalog_push_enabled) {
            $channel = $shop?->channel?->name ?? 'channel';
            $shopName = $shop?->shop_name ? " untuk toko {$shop->shop_name}" : '';

            throw new DomainException(
                "Upload katalog{$shopName} dinonaktifkan. Aktifkan catalog push terlebih dahulu di pengaturan {$channel}."
            );
        }
    }

    protected function requireChannelShopId(string $shopId): string
    {
        $channelShopId = ChannelShop::where('shop_id', $shopId)->value('id');

        if (! $channelShopId) {
            throw new RuntimeException('Toko tidak ditemukan atau tidak aktif', 422);
        }

        return $channelShopId;
    }
}
