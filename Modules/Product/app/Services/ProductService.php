<?php

namespace Modules\Product\Services;

use App\Models\User;
use App\Services\UploadService;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Channel\Jobs\SyncProductToChannelJob;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ChannelListingValidator;
use Modules\Finance\Support\AccountMappingKey;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Support\StockSummary;
use Modules\Product\Exceptions\ProductDeletionBlockedException;
use Modules\Product\Jobs\MirrorProductMediaJob;
use Modules\Product\Models\Attribute;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductBundleItem;
use Modules\Product\Models\ProductDeleteAudit;
use Modules\Product\Models\ProductMedia;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductSyncLog;
use Modules\Product\Repositories\ProductRepository;
use Modules\Product\Repositories\ProductWriteRepository;
use Modules\Product\Support\ChannelSku;
use Modules\Product\Support\InternalMediaUrl;
use Modules\Product\Support\ProductIngestSanitizer;
use Ramsey\Uuid\Uuid;

class ProductService
{
    private const DETAIL_RELATIONS = [
        'variants.channelMappings.channelMapping',
        'variants.inventories',
        'media',
        'category',
        'channelMappings.channelShop.channel',
    ];

    public function __construct(
        private readonly ProductRepository $repository,
        private readonly ProductWriteRepository $writeRepository,
        private readonly UploadService $uploadService,
        private readonly MediaCleanupService $mediaCleanup,
    ) {}

    private function buildMediaRow(array $m, string $productId, ?string $variantId): array
    {
        $mediaUuid = $m['media_uuid'] ?? null;
        $url = $m['url'] ?? null;

        if ($mediaUuid && empty($url)) {
            $url = $this->uploadService->findByUuid($mediaUuid)?->getUrl();
        }

        return [
            'product_id' => $productId,
            'variant_id' => $variantId,
            'media_uuid' => $mediaUuid,
            'media_type' => $m['media_type'] ?? 'image',
            'url' => $url,
            'sort_order' => $m['sort_order'] ?? 0,
            'is_primary' => $m['is_primary'] ?? false,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function resolveAccountId(?string $given, string $mappingKey): ?string
    {
        if (! empty($given)) {
            return $given;
        }

        return $this->writeRepository->accountIdByMappingKey($mappingKey);
    }

    private function taxRate($taxId): float
    {
        return $this->writeRepository->taxRate($taxId);
    }

    public function resolveChannelShopId(string $shopId): ?string
    {
        return ChannelShop::where('shop_id', $shopId)->value('id');
    }

    public function getProductBySku(string $sku): ?Product
    {
        return $this->repository->findBySku($sku, self::DETAIL_RELATIONS);
    }

    public function getBundles(): LengthAwarePaginator
    {
        return $this->repository->paginateBundles();
    }

    public function getStocksByIds(array $ids): Collection
    {
        return $this->repository->getByIdsWithStock($ids);
    }

    public function getPricesByIds(array $ids): Collection
    {
        return $this->repository->getByIdsWithVariants($ids);
    }

    public function findProduct(string $id, array $with = []): ?Product
    {
        $normalizedId = str_replace('-', '', $id);
        if (strlen($normalizedId) !== 32 || ! ctype_xdigit($normalizedId)) {
            return null;
        }

        return $this->repository->findWithRelations($id, $with);
    }

    public function paginateIndex(?string $status = null): LengthAwarePaginator
    {
        return $this->repository->paginateIndex($status);
    }

    public function paginateUploadable(string $channelShopId): LengthAwarePaginator
    {
        return $this->repository->paginateUploadable($channelShopId);
    }

    public function paginateVariants(string $productId): LengthAwarePaginator
    {
        return $this->repository->paginateVariants($productId);
    }

    public function paginateListedVariants(string $productId): LengthAwarePaginator
    {
        return $this->repository->paginateListedVariants($productId);
    }

    public function paginatePriceBook(string $productId): LengthAwarePaginator
    {
        return $this->repository->paginatePriceBook($productId);
    }

    public function unlinkChannelMapping(string $productId, string $mappingId): void
    {
        DB::transaction(function () use ($productId, $mappingId): void {
            $mapping = $this->repository->findChannelMappingForProduct($productId, $mappingId);

            if (! $mapping) {
                throw new DomainException('Tautan channel tidak ditemukan.');
            }

            if ($mapping->sync_status === 'syncing') {
                throw new \RuntimeException(
                    'Tidak dapat menghapus tautan saat proses sinkronisasi sedang berjalan.',
                    409,
                );
            }

            $shopId = $mapping->channel_shop_id;
            $this->repository->deleteChannelMapping($mapping);

            ProductSyncLog::record([
                'product_id' => $productId,
                'channel_shop_id' => $shopId,
                'action' => ProductSyncLog::ACTION_UNLINK,
                'status' => ProductSyncLog::STATUS_SUCCESS,
            ]);
        });
    }

    public function unlinkVariantChannelMapping(string $productId, string $variantMappingId): void
    {
        DB::transaction(function () use ($productId, $variantMappingId): void {
            $variantMapping = $this->repository->findVariantChannelMappingForProduct($productId, $variantMappingId);

            if (! $variantMapping) {
                throw new DomainException('Tautan varian channel tidak ditemukan.');
            }

            $parentMappingId = (string) $variantMapping->product_channel_mapping_id;
            $this->repository->deleteVariantChannelMapping($variantMapping);

            if ($this->repository->countVariantChannelMappings($parentMappingId) === 0) {
                $parentMapping = $this->repository->findChannelMapping((string) $parentMappingId);
                if ($parentMapping) {
                    $this->repository->deleteChannelMapping($parentMapping);
                }
            }
        });
    }

    public function bulkUnlinkChannelMappings(string $productId, array $variantMappingIds): int
    {
        return DB::transaction(function () use ($productId, $variantMappingIds): int {
            $variantMappings = $this->repository->findVariantChannelMappingsForProduct($productId, $variantMappingIds);
            $parentMappingIds = $variantMappings->pluck('product_channel_mapping_id')->unique()->values();

            foreach ($variantMappings as $variantMapping) {
                $this->repository->deleteVariantChannelMapping($variantMapping);
            }

            foreach ($parentMappingIds as $parentMappingId) {
                if ($this->repository->countVariantChannelMappings((string) $parentMappingId) !== 0) {
                    continue;
                }

                $parentMapping = $this->repository->findChannelMapping((string) $parentMappingId);
                if ($parentMapping) {
                    $this->repository->deleteChannelMapping($parentMapping);
                }
            }

            return $variantMappings->count();
        });
    }

    public function resyncChannelMapping(string $productId, string $mappingId): void
    {
        DB::transaction(function () use ($productId, $mappingId): void {
            $mapping = $this->repository->findChannelMappingForProduct($productId, $mappingId);

            if (! $mapping) {
                throw new DomainException('Tautan channel tidak ditemukan.');
            }

            $this->repository->markChannelMappingSyncing($mapping);
            SyncProductToChannelJob::dispatch(
                $productId,
                $mapping->channel_shop_id,
                'sync_price_stock',
            )->afterCommit();

            ProductSyncLog::record([
                'product_id' => $productId,
                'channel_shop_id' => $mapping->channel_shop_id,
                'action' => ProductSyncLog::ACTION_SYNC_STOCK,
                'status' => ProductSyncLog::STATUS_PENDING,
            ]);
        });
    }

    public function createOrUpdateBundle(array $data): Product
    {
        $this->guardBundleComposition($data['components']);
        $this->guardBundleConversion($data['id'] ?? null);

        $attributes = [
            'name' => $data['name'],
            'sku' => $data['sku'] ?? null,
            'category_id' => $data['category_id'],
            'is_bundle' => true,
        ];

        return $this->repository->saveBundle($data['id'] ?? null, $attributes, $data['components']);
    }

    private function guardBundleComposition(array $components): void
    {
        $variantIds = array_values(array_filter(array_column($components, 'variant_id')));

        if ($this->repository->variantIdsFromBundleProducts($variantIds) !== []) {
            throw new DomainException(
                'Komponen bundle tidak boleh berisi produk bundle (bundle-in-bundle tidak diizinkan).'
            );
        }
    }

    private function guardBundleConversion(?string $productId): void
    {
        if ($productId === null) {
            return;
        }

        if ($this->repository->currentIsBundle($productId) === true) {
            return;
        }

        $reason = $this->repository->transactionLockReason($productId);

        if ($reason !== null) {
            throw new DomainException(
                "Produk tidak dapat diubah menjadi bundle karena {$reason}."
            );
        }

        if (ProductVariant::where('product_id', $productId)->count() > 1) {
            throw new DomainException(
                'Produk dengan lebih dari satu varian tidak dapat diubah menjadi bundle (bundle harus satu varian).'
            );
        }
    }

    public function deleteProduct(Product $product, ?string $actorId = null, ?string $requestId = null): void
    {
        $result = $this->bulkDelete([$product->id], $actorId, $requestId);

        if ($result['failed'] > 0) {
            throw new DomainException($result['errors'][0] ?? 'Produk tidak dapat dihapus.');
        }
    }

    public function bulkDelete(array $ids, ?string $actorId = null, ?string $requestId = null): array
    {
        $ids = array_values(array_unique(array_map(static fn ($id): string => (string) $id, $ids)));
        $batchId = (string) Str::uuid();
        $actor = $actorId
            ? User::query()->find($actorId, ['id', 'name', 'email'])
            : null;
        $requestedProducts = Product::withTrashed()
            ->whereIn('id', $ids)
            ->get(['id', 'name', 'sku', 'deleted_at'])
            ->map(static fn (Product $product): array => [
                'id' => (string) $product->id,
                'name' => (string) $product->name,
                'sku' => $product->sku,
                'deleted_at' => $product->deleted_at?->toISOString(),
            ])
            ->values()
            ->all();

        $audit = ProductDeleteAudit::create([
            'batch_id' => $batchId,
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'actor_email' => $actor?->email,
            'request_id' => $requestId,
            'status' => ProductDeleteAudit::STATUS_PENDING,
            'requested_count' => count($ids),
            'product_snapshots' => $requestedProducts,
            'media_cleanup_status' => ProductDeleteAudit::MEDIA_CLEANUP_PENDING,
        ]);

        $mediaUuids = [];

        try {
            DB::transaction(function () use ($ids, $audit, &$mediaUuids): void {
                $products = Product::query()
                    ->whereIn('id', $ids)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($products->count() !== count($ids)) {
                    $foundIds = $products->pluck('id')->map(static fn ($id): string => (string) $id)->all();
                    $missingIds = array_values(array_diff($ids, $foundIds));
                    throw new DomainException('Produk tidak ditemukan atau sudah dihapus: '.implode(', ', $missingIds));
                }

                $blockers = [];
                $productVariants = [];

                foreach ($products as $product) {
                    $variantIds = $product->variants()->pluck('id');
                    $productVariants[(string) $product->id] = $variantIds;

                    $stockOnHand = $variantIds->isEmpty()
                        ? 0
                        : (int) Inventory::whereIn('item_id', $variantIds)->sum('on_hand');

                    if ($stockOnHand > 0) {
                        $blockers[(string) $product->id] =
                            "{$product->name}: masih memiliki stok ({$stockOnHand} unit). Gunakan Arsip untuk menonaktifkan produk yang masih bergerak.";

                        continue;
                    }

                    $bundleProductIds = ProductBundleItem::whereIn('component_variant_id', $variantIds)
                        ->distinct()
                        ->pluck('bundle_product_id');

                    if ($bundleProductIds->isNotEmpty()) {
                        $bundleSkus = Product::whereIn('id', $bundleProductIds)->pluck('sku')->filter()->implode(', ');
                        $blockers[(string) $product->id] =
                            "{$product->name}: dipakai sebagai komponen bundle ({$bundleSkus}). Lepaskan dari bundle terkait sebelum menghapus.";
                    }
                }

                if ($blockers !== []) {
                    throw new ProductDeletionBlockedException($blockers);
                }

                foreach ($products as $product) {
                    $variantIds = $productVariants[(string) $product->id];
                    $mediaUuids = array_merge($mediaUuids, $this->mediaCleanup->collectByProduct((string) $product->id));

                    DB::table('product_media')
                        ->where('product_id', $product->id)
                        ->orWhereIn('variant_id', $variantIds->all())
                        ->delete();

                    $mappingIds = DB::table('product_channel_mappings')
                        ->where('product_id', $product->id)
                        ->pluck('id');

                    if ($mappingIds->isNotEmpty()) {
                        DB::table('product_variant_channel_mappings')
                            ->whereIn('product_channel_mapping_id', $mappingIds)
                            ->delete();
                        DB::table('product_channel_mappings')
                            ->where('product_id', $product->id)
                            ->delete();
                    }

                    $product->variants()->delete();
                    $product->delete();
                }

                $audit->update([
                    'status' => ProductDeleteAudit::STATUS_SUCCEEDED,
                    'media_cleanup_status' => ProductDeleteAudit::MEDIA_CLEANUP_PENDING,
                    'completed_at' => now(),
                ]);
            }, 3);

            try {
                $this->mediaCleanup->pruneOrphans(array_values(array_unique($mediaUuids)));
                $this->markMediaCleanup($audit, ProductDeleteAudit::MEDIA_CLEANUP_SUCCEEDED);
            } catch (\Throwable $e) {
                $this->markMediaCleanup(
                    $audit,
                    ProductDeleteAudit::MEDIA_CLEANUP_FAILED,
                    $e->getMessage(),
                );
                Log::warning('Product media cleanup failed after product deletion', [
                    'batch_id' => $batchId,
                    'request_id' => $requestId,
                    'media_count' => count($mediaUuids),
                    'exception' => $e,
                ]);
            }

            return [
                'success' => count($ids),
                'failed' => 0,
                'errors' => [],
                'batch_id' => $batchId,
            ];
        } catch (ProductDeletionBlockedException $e) {
            $this->markDeleteAuditFailed($audit, ProductDeleteAudit::STATUS_BLOCKED, 'BUSINESS_RULE', $e->blockers());

            return [
                'success' => 0,
                'failed' => count($ids),
                'errors' => array_values($e->blockers()),
                'batch_id' => $batchId,
            ];
        } catch (DomainException $e) {
            $this->markDeleteAuditFailed($audit, ProductDeleteAudit::STATUS_BLOCKED, 'NOT_FOUND', [$e->getMessage()]);

            return [
                'success' => 0,
                'failed' => count($ids),
                'errors' => [$e->getMessage()],
                'batch_id' => $batchId,
            ];
        } catch (\Throwable $e) {
            $this->markDeleteAuditFailed($audit, ProductDeleteAudit::STATUS_FAILED, 'UNEXPECTED_ERROR', [$e->getMessage()]);
            Log::error('Product bulk deletion failed', [
                'batch_id' => $batchId,
                'request_id' => $requestId,
                'actor_id' => $actorId,
                'product_ids' => $ids,
                'exception' => $e,
            ]);

            throw $e;
        }
    }

    private function markDeleteAuditFailed(ProductDeleteAudit $audit, string $status, string $code, array $messages): void
    {
        try {
            $audit->update([
                'status' => $status,
                'failure_code' => $code,
                'failure_message' => Str::limit(implode('; ', $messages), 4000),
                'completed_at' => now(),
            ]);
        } catch (\Throwable $auditException) {
            Log::error('Product delete audit could not be updated', [
                'batch_id' => $audit->batch_id,
                'exception' => $auditException,
            ]);
        }
    }

    private function markMediaCleanup(ProductDeleteAudit $audit, string $status, ?string $error = null): void
    {
        try {
            $audit->update([
                'media_cleanup_status' => $status,
                'media_cleanup_error' => $error !== null ? Str::limit($error, 2000) : null,
            ]);
        } catch (\Throwable $auditException) {
            Log::error('Product media cleanup audit could not be updated', [
                'batch_id' => $audit->batch_id,
                'media_cleanup_status' => $status,
                'exception' => $auditException,
            ]);
        }
    }

    public function upsertFromChannel(
        array $data,
        ?bool &$matchedExisting = null,
        ?array &$variantIds = null,
        bool $strictSku = false,
    )
    {
        $data = ProductIngestSanitizer::sanitize($data);

        $externalProductId = isset($data['channel_external_product_id'])
            ? (string) $data['channel_external_product_id']
            : null;

        $data['variants'] = $strictSku
            ? $this->onlyValidChannelVariants($data['variants'] ?? [], $externalProductId)
            : $this->dropVariantsWithoutSku($data['variants'] ?? [], $externalProductId);

        if ($strictSku && $data['variants'] === []) {
            throw new DomainException(
                'Produk channel dilewati karena tidak memiliki SKU penjual yang valid.'
                .($externalProductId ? " Listing {$externalProductId}." : '')
            );
        }

        $variantIds = [];
        $productId = $this->resolveExistingProductFromChannel($data);

        if ($productId) {
            $matchedExisting = true;

            return $productId;
        }

        $matchedExisting = false;

        $data['variants'] = $this->makeDeletedChannelVariantSkusUnique($data, $strictSku);

        $productId = $this->createProduct($data, $variantIds, false);
        $this->queueExternalMediaMirroring($productId);

        return $productId;
    }

    private function dropVariantsWithoutSku(array $variants, ?string $externalProductId = null): array
    {
        $withSku = array_values(array_filter(
            $variants,
            fn ($v) => ! ChannelSku::isPlaceholder($v['sku'] ?? null, $externalProductId)
        ));

        if ($withSku) {
            return $withSku;
        }

        $placeholder = array_slice(array_values($variants), 0, 1);

        if ($placeholder && is_array($placeholder[0])) {
            $placeholder[0]['sku'] = null;
        }

        return $placeholder;
    }

    private function onlyValidChannelVariants(array $variants, ?string $externalProductId): array
    {
        $valid = [];
        $seen = [];

        foreach ($variants as $variant) {
            if (! is_array($variant)) {
                continue;
            }

            $sku = ChannelSku::normalize($variant['sku'] ?? null, $externalProductId);
            if ($sku === null || isset($seen[$sku])) {
                continue;
            }

            $variant['sku'] = $sku;
            $seen[$sku] = true;
            $valid[] = $variant;
        }

        return $valid;
    }

    private function makeDeletedChannelVariantSkusUnique(array $data, bool $strictSku = false): array
    {
        $externalProductId = trim((string) ($data['channel_external_product_id'] ?? ''));
        if ($externalProductId === '') {
            return $data['variants'] ?? [];
        }

        $skus = collect($data['variants'] ?? [])
            ->pluck('sku')
            ->filter(fn ($sku) => is_string($sku) && trim($sku) !== '')
            ->map(fn ($sku) => trim($sku))
            ->unique()
            ->values()
            ->all();

        if ($skus === []) {
            return $data['variants'] ?? [];
        }

        $blocked = DB::table('product_variants as pv')
            ->join('products as p', 'p.id', '=', 'pv.product_id')
            ->whereIn('pv.sku', $skus)
            ->whereNull('pv.deleted_at')
            ->whereNotNull('p.deleted_at')
            ->pluck('pv.sku')
            ->map(fn ($sku) => (string) $sku)
            ->flip();

        if ($blocked->isEmpty()) {
            return $data['variants'] ?? [];
        }

        if ($strictSku) {
            throw new DomainException(
                'SKU channel sudah dipakai oleh varian aktif di master yang terhapus: '
                .$blocked->keys()->implode(', ')
                .'. Pulihkan atau rapikan master lama terlebih dahulu.'
            );
        }

        foreach ($data['variants'] as &$variant) {
            $sku = trim((string) ($variant['sku'] ?? ''));
            if (! isset($blocked[$sku])) {
                continue;
            }

            $base = $sku.'-REIMPORT-'.substr(sha1($externalProductId.':'.$sku), 0, 8);
            $candidate = $base;
            $suffix = 2;

            while (DB::table('product_variants')->where('sku', $candidate)->exists()) {
                $candidate = $base.'-'.$suffix++;
            }

            $variant['sku'] = $candidate;
        }
        unset($variant);

        return $data['variants'];
    }

    public function addVariantFromChannel(string $productId, array $variant): ?string
    {
        $sku = ChannelSku::normalize($variant['sku'] ?? null);

        if ($sku === null) {
            return null;
        }

        return DB::transaction(function () use ($productId, $variant, $sku) {
            $existing = $this->writeRepository->variantIdByProductAndSku($productId, $sku);

            if ($existing) {
                return $existing;
            }

            $variantData = Arr::only($variant, [
                'barcode', 'buy_price', 'sell_price', 'is_active',
                'min_stock', 'safe_stock', 'weight', 'length', 'width', 'height',
            ]);

            $variantId = Uuid::uuid7()->toString();
            $this->writeRepository->insertVariantRow(array_merge($variantData, [
                'id' => $variantId,
                'product_id' => $productId,
                'sku' => $sku,
                'created_at' => now(),
                'updated_at' => now(),
            ]));

            $options = $this->channelOptionRows($productId, $variantId, $variant['options'] ?? []);

            if ($options) {
                $this->writeRepository->insertVariantOptions($options);
            }

            if (! empty($variant['media'])) {
                $this->writeRepository->insertMedia(array_map(
                    fn ($m) => $this->buildMediaRow($m, $productId, $variantId),
                    $variant['media']
                ));
            }

            return $variantId;
        });
    }

    public function backfillVariantOptionsFromChannel(string $productId, string $variantId, array $options): bool
    {
        $bersih = array_values(array_filter(
            $options,
            fn ($o) => is_array($o)
                && trim((string) ($o['name'] ?? '')) !== ''
                && trim((string) ($o['value'] ?? '')) !== ''
        ));

        if (! $bersih) {
            return false;
        }

        if (DB::table('variant_options')->where('variant_id', $variantId)->exists()) {
            return false;
        }

        return DB::transaction(function () use ($productId, $variantId, $bersih) {
            $terpasang = DB::table('product_variation_types as pvt')
                ->join('attributes as a', 'a.id', '=', 'pvt.attribute_id')
                ->where('pvt.product_id', $productId)
                ->pluck('pvt.attribute_id', 'a.name')
                ->all();

            $urutan = (int) DB::table('product_variation_types')
                ->where('product_id', $productId)
                ->max('sort_order');

            $rows = [];

            foreach ($bersih as $opt) {
                $nama = trim((string) $opt['name']);
                $attributeId = $terpasang[$nama] ?? null;

                if (! $attributeId) {
                    $attributeId = Attribute::firstOrCreate(['name' => $nama], ['type' => 'sales'])->id;
                    $this->writeRepository->insertVariationType([
                        'product_id' => $productId,
                        'attribute_id' => $attributeId,
                        'sort_order' => ++$urutan,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $terpasang[$nama] = $attributeId;
                }

                $rows[] = [
                    'variant_id' => $variantId,
                    'attribute_id' => $attributeId,
                    'value' => trim((string) $opt['value']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            $this->writeRepository->insertVariantOptions($rows);

            return true;
        });
    }

    private function channelOptionRows(string $productId, string $variantId, array $options): array
    {
        if (empty($options)) {
            return [];
        }

        $attributeIds = $this->writeRepository->variationTypeAttributeIds($productId);
        $rows = [];

        foreach (array_values($options) as $i => $opt) {
            $attributeId = $opt['attribute_id'] ?? ($attributeIds[$i] ?? null);
            $value = trim((string) ($opt['value'] ?? ''));

            if (! $attributeId || $value === '') {
                continue;
            }

            $rows[] = [
                'variant_id' => $variantId,
                'attribute_id' => $attributeId,
                'value' => $value,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return $rows;
    }

    private function resolveExistingProductFromChannel(array $data): ?string
    {
        $externalProductId = $data['channel_external_product_id'] ?? null;
        $externalShopId = $data['channel_shop_id_external'] ?? null;

        if ($externalProductId && $externalShopId) {
            $productId = $this->writeRepository->productIdByChannelExternalId(
                (string) $externalShopId,
                (string) $externalProductId
            );

            if ($productId) {
                return $productId;
            }
        }

        $parentSku = $data['sku'] ?? null;

        if ($parentSku) {
            $productId = $this->writeRepository->productIdBySku($parentSku);

            if ($productId) {
                return $productId;
            }
        }

        return $this->writeRepository->productIdWithMostMatchingVariantSkus(
            array_column($data['variants'] ?? [], 'sku')
        );
    }

    private function queueExternalMediaMirroring(string $productId): void
    {
        ProductMedia::query()
            ->where('product_id', $productId)
            ->whereNotNull('url')
            ->get(['id', 'url'])
            ->each(function ($row) {
                if (InternalMediaUrl::isExternal($row->url)) {
                    MirrorProductMediaJob::dispatch($row->id);
                }
            });
    }

    public function updateProduct(string $productId, array $data)
    {
        $this->resolveCustomAttributes($data);

        if (array_key_exists('specifications', $data) || array_key_exists('variation_types', $data)) {
            $categoryId = $data['category_id'] ?? $this->writeRepository->productCategoryId($productId);
            $this->assertCategoryAttributes($categoryId ? (int) $categoryId : null, $data, array_key_exists('specifications', $data));
        }

        $mediaUuidsBefore = $this->mediaCleanup->collectByProduct($productId);

        $result = DB::transaction(function () use ($productId, $data) {
            $productData = Arr::only($data, [
                'name', 'sku', 'description', 'category_id', 'search_keyword',
                'order_type', 'indent_days', 'condition', 'status',
                'weight', 'weight_unit', 'length', 'width', 'height', 'is_active', 'is_cod_allowed',
                'is_bundle', 'is_consignment', 'package_contents',
                'is_stored', 'is_sold', 'is_purchased', 'purchase_lead_time',
            ]);

            if (isset($productData['sku']) && trim((string) $productData['sku']) === '') {
                $productData['sku'] = null;
            }

            if (empty($productData['sku']) && ! empty($data['variants']) && count($data['variants']) === 1 && ! empty($data['variants'][0]['sku'])) {
                $productData['sku'] = trim((string) $data['variants'][0]['sku']);
            }

            foreach (['weight', 'length', 'width', 'height'] as $dim) {
                if (array_key_exists($dim, $productData) && $productData[$dim] === null) {
                    unset($productData[$dim]);
                }
            }

            foreach ([
                'sales_account_id', 'sales_return_account_id',
                'inventory_account_id', 'cogs_account_id',
            ] as $accCol) {
                if (array_key_exists($accCol, $data)) {
                    $productData[$accCol] = $data[$accCol];
                }
            }

            if (! empty($productData)) {
                $this->writeRepository->updateProductRow($productId, $productData);
            }

            if (array_key_exists('media', $data)) {
                $this->writeRepository->deleteProductLevelMedia($productId);

                if (! empty($data['media'])) {
                    $this->writeRepository->insertMedia(array_map(
                        fn ($m) => $this->buildMediaRow($m, $productId, null),
                        $data['media']
                    ));
                }
            }

            if (array_key_exists('variation_types', $data)) {

                $this->syncVariantStructure($productId, $data);
            } elseif (! empty($data['variants'])) {
                foreach ($data['variants'] as $variant) {
                    if (empty($variant['sku'])) {
                        continue;
                    }

                    $variantData = Arr::only($variant, [
                        'sell_price', 'buy_price', 'barcode', 'is_active',
                        'sales_tax_id', 'purchase_tax_id', 'min_stock', 'safe_stock',
                        'weight', 'length', 'width', 'height',
                    ]);
                    foreach (['weight', 'length', 'width', 'height'] as $dim) {
                        if (array_key_exists($dim, $variantData) && $variantData[$dim] === null) {
                            unset($variantData[$dim]);
                        }
                    }
                    if (! empty($variant['sales_tax_id'])) {
                        $variantData['tax_rate'] = $this->taxRate($variant['sales_tax_id']);
                    }
                    $variantData['updated_at'] = now();

                    $existingVariant = $this->writeRepository->findVariantByProductAndSku($productId, $variant['sku']);

                    if ($existingVariant) {
                        $this->writeRepository->updateVariantRow($existingVariant->id, $variantData);
                        $variantId = $existingVariant->id;
                    } else {
                        $this->writeRepository->insertVariantRow(array_merge($variantData, [
                            'id' => Uuid::uuid7()->toString(),
                            'product_id' => $productId,
                            'sku' => $variant['sku'],
                            'created_at' => now(),
                        ]));
                        $variantId = $this->writeRepository->variantIdByProductAndSku($productId, $variant['sku']);
                    }

                    if (array_key_exists('media', $variant)) {
                        $this->writeRepository->deleteVariantMedia($variantId);

                        if (! empty($variant['media'])) {
                            $this->writeRepository->insertMedia(array_map(
                                fn ($m) => $this->buildMediaRow($m, $productId, $variantId),
                                $variant['media']
                            ));
                        }
                    }

                    if (array_key_exists('unlimited_shop_ids', $variant)) {
                        $this->writeRepository->deleteUnlimitedShops($variantId);
                        if (! empty($variant['unlimited_shop_ids'])) {
                            $this->writeRepository->syncUnlimitedShops($variantId, $variant['unlimited_shop_ids']);
                        }
                    }
                }
            }

            if (array_key_exists('specifications', $data)) {
                $this->syncSpecifications($productId, $data['specifications'] ?? []);
            }

            return $productId;
        });

        if (array_key_exists('variation_types', $data)) {
            $this->propagateVariantChangeToChannels($productId);
        }

        if (! empty($data['variants'])) {
            $this->propagatePriceStockToChannels($productId);
        }

        $this->mediaCleanup->pruneOrphans($mediaUuidsBefore);

        return $result;
    }

    private function normalizeOptionValue(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    private function syncVariantStructure(string $productId, array $data): void
    {
        $types = $data['variation_types'] ?? [];
        $payloadVariants = $data['variants'] ?? [];

        $existingTypes = $this->writeRepository->variationTypeAttributeIds($productId);

        $activeVariants = $this->writeRepository->activeVariants($productId);

        $optRows = $this->writeRepository->variantOptionsFor($activeVariants->pluck('id')->all());

        $setByVariant = [];
        $existingValues = [];
        foreach ($optRows as $o) {
            $setByVariant[$o->variant_id][(int) $o->attribute_id] = (string) $o->value;
            $existingValues[(int) $o->attribute_id][$this->normalizeOptionValue((string) $o->value)] = (string) $o->value;
        }

        $payloadTypes = array_map(static fn ($t) => (int) $t['attribute_id'], $types);
        $payloadValues = [];
        foreach ($payloadVariants as $v) {
            foreach ($v['options'] ?? [] as $opt) {
                $payloadValues[(int) $opt['attribute_id']][$this->normalizeOptionValue((string) $opt['value'])] = true;
            }
        }

        foreach ($existingTypes as $et) {
            if (! in_array($et, $payloadTypes, true)) {
                throw new DomainException('Jenis varian yang sudah tersimpan tidak boleh dihapus.');
            }
        }

        $this->assertVariationConstraints($data);

        $keyOfOpts = function (array $opts): string {
            $p = [];
            foreach ($opts as $o) {
                $p[(int) $o['attribute_id']] = $this->normalizeOptionValue((string) $o['value']);
            }
            ksort($p);

            return implode('|', array_map(static fn ($k, $v) => "{$k}:{$v}", array_keys($p), $p));
        };
        $keyOfSet = function (array $set): string {
            $p = [];
            foreach ($set as $a => $v) {
                $p[(int) $a] = $this->normalizeOptionValue((string) $v);
            }
            ksort($p);

            return implode('|', array_map(static fn ($k, $v) => "{$k}:{$v}", array_keys($p), $p));
        };

        $existingByKey = [];
        foreach ($activeVariants as $av) {
            $existingByKey[$keyOfSet($setByVariant[$av->id] ?? [])] = $av;
        }

        $desired = [];
        $payloadSkus = [];
        foreach ($payloadVariants as $v) {
            $desired[$keyOfOpts($v['options'] ?? [])] = true;
            if (! empty($v['sku'])) {
                $payloadSkus[] = $v['sku'];
            }
        }

        $foreignSkus = $this->writeRepository->skusUsedByOtherProducts($productId, $payloadSkus);
        if ($foreignSkus) {
            throw new DomainException('SKU varian sudah digunakan produk lain: '.implode(', ', $foreignSkus));
        }

        $toSupersede = [];
        foreach ($existingByKey as $key => $av) {
            if (! isset($desired[$key])) {
                $toSupersede[] = $av;
            }
        }

        if ($toSupersede) {
            $stock = StockSummary::forItems(array_map(fn ($av) => $av->id, $toSupersede));
            $blocked = [];
            foreach ($toSupersede as $av) {
                $s = $stock[$av->id] ?? null;
                $qty = $s
                    ? (int) $s['on_hand'] + (int) $s['pending_placement'] + (int) $s['on_order'] + (int) $s['transit']
                    : 0;
                if ($qty > 0) {
                    $blocked[] = $av->sku;
                }
            }
            if ($blocked) {
                throw new DomainException(
                    'Opsi varian tidak bisa dihapus karena varian berikut masih punya stok: '.implode(', ', $blocked)
                );
            }
            foreach ($toSupersede as $av) {
                $this->writeRepository->supersedeVariant($av->id);
            }
        }
        $this->writeRepository->freeInactiveVariantSkus($productId, $payloadSkus);

        foreach ($payloadVariants as $v) {
            $opts = $v['options'] ?? [];
            $key = $keyOfOpts($opts);

            if (isset($existingByKey[$key])) {
                $variantId = $existingByKey[$key]->id;
                $upd = $this->variantUpdatableFields($v);
                if ($upd) {
                    $upd['updated_at'] = now();
                    $this->writeRepository->updateVariantRow($variantId, $upd);
                }
                $this->syncVariantMediaFromPayload($productId, $variantId, $v);

                continue;
            }

            $price = $v['sell_price'] ?? $this->ancestorPrice($opts, $activeVariants, $setByVariant);
            $variantId = Uuid::uuid7()->toString();
            $this->writeRepository->insertVariantRow(array_merge(
                $this->variantUpdatableFields($v),
                [
                    'id' => $variantId,
                    'product_id' => $productId,

                    'sku' => $v['sku'] ?? null,
                    'sell_price' => $price ?? 0,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            ));
            foreach ($opts as $o) {
                $this->writeRepository->insertVariantOptions([[
                    'variant_id' => $variantId,
                    'attribute_id' => (int) $o['attribute_id'],
                    'value' => trim((string) $o['value']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]]);
            }
            $this->syncVariantMediaFromPayload($productId, $variantId, $v);
        }

        foreach ($types as $t) {
            $attrId = (int) $t['attribute_id'];
            if (! $this->writeRepository->variationTypeExists($productId, $attrId)) {
                $this->writeRepository->insertVariationType([
                    'product_id' => $productId,
                    'attribute_id' => $attrId,
                    'sort_order' => $t['sort_order'] ?? 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function syncVariantMediaFromPayload(string $productId, string $variantId, array $variant): void
    {
        if (! array_key_exists('media', $variant)) {
            return;
        }

        $this->writeRepository->deleteVariantMedia($variantId);

        if (! empty($variant['media'])) {
            $this->writeRepository->insertMedia(array_map(
                fn ($m) => $this->buildMediaRow($m, $productId, $variantId),
                $variant['media']
            ));
        }
    }

    private function variantUpdatableFields(array $v): array
    {
        $f = Arr::only($v, [
            'buy_price', 'barcode', 'is_active',
            'sales_tax_id', 'purchase_tax_id', 'min_stock', 'safe_stock',
            'weight', 'length', 'width', 'height',
        ]);
        foreach (['weight', 'length', 'width', 'height'] as $dim) {
            if (array_key_exists($dim, $f) && $f[$dim] === null) {
                unset($f[$dim]);
            }
        }
        if (array_key_exists('sell_price', $v) && $v['sell_price'] !== null) {
            $f['sell_price'] = $v['sell_price'];
        }
        if (! empty($v['sku'])) {
            $f['sku'] = $v['sku'];
        }
        if (! empty($v['sales_tax_id'])) {
            $f['tax_rate'] = $this->taxRate($v['sales_tax_id']);
        }

        return $f;
    }

    private function ancestorPrice(array $opts, $activeVariants, array $setByVariant): ?float
    {
        $combo = [];
        foreach ($opts as $o) {
            $combo[(int) $o['attribute_id']] = $this->normalizeOptionValue((string) $o['value']);
        }

        foreach ($activeVariants as $av) {
            $set = $setByVariant[$av->id] ?? [];
            if (empty($set)) {
                continue;
            }
            $subset = true;
            foreach ($set as $a => $val) {
                if (($combo[(int) $a] ?? null) !== $this->normalizeOptionValue((string) $val)) {
                    $subset = false;
                    break;
                }
            }
            if ($subset) {
                return (float) $av->sell_price;
            }
        }

        return null;
    }

    private function syncSpecifications(string $productId, array $specs): void
    {
        $this->writeRepository->deleteSpecifications($productId);
        if (empty($specs)) {
            return;
        }

        $this->writeRepository->insertSpecifications(array_map(static fn ($s) => [
            'product_id' => $productId,
            'attribute_id' => $s['attribute_id'],
            'attribute_option_id' => $s['attribute_option_id'] ?? null,
            'text_value' => $s['text_value'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $specs));
    }

    public function bulkUpdateVariants(Product $product, string $action, array $ids): array
    {
        $variants = $this->writeRepository->variantsForBulk($product->id, $ids);

        if ($variants->isEmpty()) {
            throw new DomainException('Tidak ada varian yang cocok untuk produk ini.');
        }

        $total = $this->writeRepository->countVariants($product->id);
        if ($variants->count() >= $total) {
            throw new DomainException('Tidak bisa menghapus semua varian; produk butuh minimal 1 varian.');
        }

        $this->writeRepository->deleteVariants($variants->pluck('id')->all());

        return ['deleted' => $variants->count()];
    }

    private function propagateVariantChangeToChannels(string $productId): void
    {

        if (! config('channel.auto_push_product_content', false)) {
            return;
        }

        $channelShopIds = $this->writeRepository->channelShopIdsForActiveMappings($productId);

        foreach ($channelShopIds as $channelShopId) {
            SyncProductToChannelJob::dispatch($productId, $channelShopId, 'update');
        }
    }

    private function propagatePriceStockToChannels(string $productId): void
    {
        foreach ($this->writeRepository->channelShopIdsForStockPriceSync($productId) as $channelShopId) {
            SyncProductToChannelJob::dispatch($productId, $channelShopId, 'sync_price_stock');
        }
    }

    private function assertVariationConstraints(array $data): void
    {
        $types = $data['variation_types'] ?? [];

        if (count($types) > 2) {
            throw new DomainException('Maksimal 2 jenis varian per produk.');
        }

        $typeAttrIds = array_map(static fn ($t) => (int) $t['attribute_id'], $types);

        if (count($typeAttrIds) !== count(array_unique($typeAttrIds))) {
            throw new DomainException('Jenis varian tidak boleh duplikat.');
        }

        $seenCombos = [];

        foreach ($data['variants'] ?? [] as $variant) {
            $options = $variant['options'] ?? [];

            foreach ($options as $opt) {
                if (! in_array((int) $opt['attribute_id'], $typeAttrIds, true)) {
                    throw new DomainException('Opsi varian memakai jenis yang tidak ada di variation_types.');
                }
            }

            $combo = collect($options)
                ->sortBy('attribute_id')
                ->map(fn ($o) => $o['attribute_id'].':'.$this->normalizeOptionValue((string) $o['value']))
                ->implode('|');

            if ($combo !== '' && isset($seenCombos[$combo])) {
                throw new DomainException('Kombinasi varian tidak boleh sama dengan varian lain.');
            }
            $seenCombos[$combo] = true;
        }
    }

    private function assertCategoryAttributes(?int $categoryId, array $data, bool $enforceRequiredSpecs): void
    {
        if (! $categoryId) {
            return;
        }

        $catAttrs = $this->writeRepository->categoryAttributesForValidation($categoryId);

        if ($catAttrs->isEmpty()) {
            return;
        }

        foreach ($data['variation_types'] ?? [] as $vt) {
            $ca = $catAttrs->get((int) $vt['attribute_id']);
            if (! $ca || $ca->type !== 'sales') {
                throw new DomainException('Jenis varian tidak berlaku untuk kategori ini.');
            }
        }

        $filledSpecs = [];
        foreach ($data['specifications'] ?? [] as $s) {
            $ca = $catAttrs->get((int) $s['attribute_id']);
            if (! $ca || $ca->type !== 'spec') {
                throw new DomainException('Spesifikasi tidak berlaku untuk kategori ini.');
            }
            $hasValue = ! empty($s['attribute_option_id'])
                || (isset($s['text_value']) && $s['text_value'] !== '' && $s['text_value'] !== null);
            if ($hasValue) {
                $filledSpecs[(int) $s['attribute_id']] = true;
            }
        }

        if (! $enforceRequiredSpecs) {
            return;
        }

        $system = array_map('mb_strtolower', ChannelListingValidator::SYSTEM_ATTRIBUTES);
        foreach ($catAttrs as $attrId => $ca) {
            if ($ca->type !== 'spec' || ! $ca->is_required) {
                continue;
            }
            if (in_array(mb_strtolower((string) $ca->name), $system, true)) {
                continue;
            }
            if (! isset($filledSpecs[(int) $attrId])) {
                throw new DomainException("Spesifikasi wajib '{$ca->name}' belum diisi.");
            }
        }
    }

    private function resolveCustomAttributes(array &$data): void
    {
        $nameToId = [];

        foreach ($data['variation_types'] ?? [] as $i => $vt) {
            if (! empty($vt['attribute_id'])) {
                continue;
            }
            $name = $vt['name'] ?? null;
            if (! $name) {
                continue;
            }

            if (! isset($nameToId[$name])) {
                $attr = Attribute::create(['name' => $name, 'type' => 'sales']);
                $nameToId[$name] = $attr->id;
            }
            $data['variation_types'][$i]['attribute_id'] = $nameToId[$name];
        }

        foreach ($data['variants'] ?? [] as $vi => $variant) {
            foreach ($variant['options'] ?? [] as $oi => $opt) {
                if (! empty($opt['attribute_id'])) {
                    continue;
                }
                $name = $opt['name'] ?? null;
                if (! $name) {
                    continue;
                }

                if (! isset($nameToId[$name])) {
                    $attr = Attribute::create(['name' => $name, 'type' => 'sales']);
                    $nameToId[$name] = $attr->id;
                }
                $data['variants'][$vi]['options'][$oi]['attribute_id'] = $nameToId[$name];
            }
        }
    }

    public function createProduct(
        array $data,
        ?array &$variantIds = null,
        bool $deriveParentSkuFromSingleVariant = true,
    ) {
        $this->resolveCustomAttributes($data);
        $this->assertVariationConstraints($data);
        $this->assertCategoryAttributes($data['category_id'] ?? null, $data, true);
        $variantIds = [];

        return DB::transaction(function () use ($data, &$variantIds, $deriveParentSkuFromSingleVariant) {
            $productData = Arr::only($data, [
                'category_id', 'name', 'sku', 'description',
                'order_type', 'indent_days',
                'weight', 'weight_unit', 'length', 'width', 'height', 'is_active',
                'is_bundle', 'is_consignment', 'is_from_channel',
                'is_stored', 'is_sold', 'is_purchased',
                'purchase_lead_time', 'package_contents',
            ]);

            if (isset($productData['sku']) && trim((string) $productData['sku']) === '') {
                $productData['sku'] = null;
            }

            if ($deriveParentSkuFromSingleVariant
                && empty($productData['sku'])
                && ! empty($data['variants'])
                && count($data['variants']) === 1
                && ! empty($data['variants'][0]['sku'])) {
                $productData['sku'] = trim((string) $data['variants'][0]['sku']);
            }

            foreach (['weight', 'length', 'width', 'height'] as $dim) {
                if (array_key_exists($dim, $productData) && $productData[$dim] === null) {
                    unset($productData[$dim]);
                }
            }

            $productData['status'] = $data['status'] ?? Product::STATUS_MASTER;
            $productData['verified_at'] = $data['verified_at'] ?? null;

            $productData['sales_account_id'] = $this->resolveAccountId($data['sales_account_id'] ?? null, AccountMappingKey::SALES_REVENUE);
            $productData['sales_return_account_id'] = $this->resolveAccountId($data['sales_return_account_id'] ?? null, AccountMappingKey::SALES_RETURN);
            $productData['inventory_account_id'] = $this->resolveAccountId($data['inventory_account_id'] ?? null, AccountMappingKey::INVENTORY);
            $productData['cogs_account_id'] = $this->resolveAccountId($data['cogs_account_id'] ?? null, AccountMappingKey::COGS);

            $productId = Uuid::uuid7()->toString();
            $this->writeRepository->insertProductRow(array_merge($productData, [
                'id' => $productId,
                'created_at' => now(),
                'updated_at' => now(),
            ]));

            if (! empty($data['specifications'])) {
                $specs = array_map(function ($spec) use ($productId) {
                    return [
                        'product_id' => $productId,
                        'attribute_id' => $spec['attribute_id'],
                        'attribute_option_id' => $spec['attribute_option_id'] ?? null,
                        'text_value' => $spec['text_value'] ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }, $data['specifications']);
                $this->writeRepository->insertSpecifications($specs);
            }

            if (! empty($data['media'])) {
                $media = array_map(
                    fn ($m) => $this->buildMediaRow($m, $productId, null),
                    $data['media']
                );
                $this->writeRepository->insertMedia($media);
            }

            if (! empty($data['variation_types'])) {
                $varTypes = array_map(function ($vt) use ($productId) {
                    return [
                        'product_id' => $productId,
                        'attribute_id' => $vt['attribute_id'],
                        'sort_order' => $vt['sort_order'] ?? 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }, $data['variation_types']);
                $this->writeRepository->insertVariationTypes($varTypes);
            }

            if (! empty($data['variants'])) {
                foreach ($data['variants'] as $variant) {
                    $variantData = Arr::only($variant, [
                        'sku', 'barcode', 'buy_price', 'sell_price', 'is_active',
                        'sales_tax_id', 'purchase_tax_id', 'min_stock', 'safe_stock',
                        'weight', 'length', 'width', 'height',
                    ]);

                    foreach (['weight', 'length', 'width', 'height'] as $dim) {
                        if (array_key_exists($dim, $variantData) && $variantData[$dim] === null) {
                            unset($variantData[$dim]);
                        }
                    }

                    if (! empty($variant['sales_tax_id'])) {
                        $variantData['tax_rate'] = $this->taxRate($variant['sales_tax_id']);
                    }

                    $variantId = Uuid::uuid7()->toString();
                    $variantIds[] = $variantId;
                    $this->writeRepository->insertVariantRow(array_merge($variantData, [
                        'id' => $variantId,
                        'product_id' => $productId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]));

                    if (! empty($variant['unlimited_shop_ids'])) {
                        $this->writeRepository->syncUnlimitedShops($variantId, $variant['unlimited_shop_ids']);
                    }

                    if (! empty($variant['options'])) {
                        $options = array_map(function ($opt) use ($variantId) {
                            return [
                                'variant_id' => $variantId,
                                'attribute_id' => $opt['attribute_id'],
                                'value' => trim((string) $opt['value']),
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];
                        }, $variant['options']);
                        $this->writeRepository->insertVariantOptions($options);
                    }

                    if (! empty($variant['media'])) {
                        $vMedia = array_map(
                            fn ($m) => $this->buildMediaRow($m, $productId, $variantId),
                            $variant['media']
                        );
                        $this->writeRepository->insertMedia($vMedia);
                    }

                    if (! empty($variant['wholesale_prices'])) {
                        $wholesales = array_map(function ($wp) use ($variantId) {
                            return [
                                'variant_id' => $variantId,
                                'min_qty' => $wp['min_qty'],
                                'price' => $wp['price'],
                                'customer_type' => $wp['customer_type'] ?? null,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];
                        }, $variant['wholesale_prices']);
                        $this->writeRepository->insertWholesalePrices($wholesales);
                    }

                }
            }

            return $productId;
        });
    }
}
