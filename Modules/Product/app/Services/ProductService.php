<?php

namespace Modules\Product\Services;

use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Modules\Channel\Jobs\SyncProductToChannelJob;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ChannelListingValidator;
use Modules\Finance\Support\AccountMappingKey;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Support\StockSummary;
use Modules\Product\Jobs\MirrorProductMediaJob;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductMedia;
use Modules\Product\Models\Attribute;
use Modules\Product\Repositories\ProductRepository;
use Modules\Product\Repositories\ProductWriteRepository;
use Modules\Product\Support\InternalMediaUrl;

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
        private readonly \App\Services\UploadService $uploadService,
    ) {
    }

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

        // Bundle wajib satu varian (bundleComponentsForVariant memetakan varian ->
        // produk; >1 varian berbagi 1 komposisi = ambigu).
        if (\Modules\Product\Models\ProductVariant::where('product_id', $productId)->count() > 1) {
            throw new DomainException(
                'Produk dengan lebih dari satu varian tidak dapat diubah menjadi bundle (bundle harus satu varian).'
            );
        }
    }

    public function deleteProduct(Product $product): void
    {
        $variantIds = $product->variants()->pluck('id');
        $stockOnHand = $variantIds->isEmpty()
            ? 0
            : (int) Inventory::whereIn('item_id', $variantIds)->sum('on_hand');

        if ($stockOnHand > 0) {
            throw new DomainException(
                "Produk masih memiliki stok ({$stockOnHand} unit). Hanya produk dead stock (stok habis) yang dapat dihapus. Gunakan Arsip untuk menonaktifkan produk yang masih bergerak."
            );
        }

        $bundleProductIds = \Modules\Product\Models\ProductBundleItem::whereIn('component_variant_id', $variantIds)
            ->distinct()
            ->pluck('bundle_product_id');

        if ($bundleProductIds->isNotEmpty()) {
            $bundleSkus = Product::whereIn('id', $bundleProductIds)->pluck('sku')->implode(', ');
            throw new DomainException(
                "Produk ini dipakai sebagai komponen bundle ({$bundleSkus}). Lepaskan dari bundle terkait sebelum menghapus."
            );
        }

        DB::transaction(function () use ($product) {
            $product->variants()->delete();
            $product->delete();
        });
    }

    public function bulkDelete(array $ids): array
    {
        $success = 0;
        $errors = [];

        foreach ($ids as $id) {
            $product = Product::find($id);
            if (!$product) {
                $errors[] = "Produk {$id} tidak ditemukan";
                continue;
            }
            try {
                $this->deleteProduct($product);
                $success++;
            } catch (DomainException $e) {
                $errors[] = "{$product->name}: {$e->getMessage()}";
            }
        }

        return ['success' => $success, 'failed' => count($errors), 'errors' => $errors];
    }

    public function upsertFromChannel(array $data, ?bool &$matchedExisting = null, ?array &$variantIds = null)
    {
        $variantIds = [];
        $parentSku = $data['sku'] ?? null;
        $productId = null;

        if ($parentSku) {
            $productId = $this->writeRepository->productIdBySku($parentSku)
                ?? $this->writeRepository->productIdByVariantSku($parentSku);
        }

        if (! $productId && ! empty($data['variants'])) {
            foreach ($data['variants'] as $variant) {
                $vSku = $variant['sku'] ?? null;
                if ($vSku) {
                    $productId = $this->writeRepository->productIdBySku($vSku)
                        ?? $this->writeRepository->productIdByVariantSku($vSku);
                    if ($productId) {
                        break;
                    }
                }
            }
        }

        if (! $productId) {
            $externalProductId = $data['channel_external_product_id'] ?? null;
            $externalShopId = $data['channel_shop_id_external'] ?? null;
            if ($externalProductId && $externalShopId) {
                $productId = $this->writeRepository->productIdByChannelExternalId(
                    (string) $externalShopId,
                    (string) $externalProductId
                );
            }
        }

        if ($productId) {
            $matchedExisting = true;

            return $productId;
        }

        $matchedExisting = false;
        $productId = $this->createProduct($data, $variantIds);
        $this->queueExternalMediaMirroring($productId);

        return $productId;
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

        $result = DB::transaction(function () use ($productId, $data) {
            $productData = Arr::only($data, [
                'name', 'sku', 'description', 'category_id', 'search_keyword',
                'order_type', 'indent_days', 'condition', 'status',
                'weight', 'weight_unit', 'length', 'width', 'height', 'is_active', 'is_cod_allowed',
                'is_bundle', 'is_consignment', 'package_contents',
                'is_stored', 'is_sold', 'is_purchased', 'purchase_lead_time',
            ]);

            foreach ([
                'sales_account_id', 'sales_return_account_id',
                'inventory_account_id', 'cogs_account_id',
            ] as $accCol) {
                if (array_key_exists($accCol, $data)) {
                    $productData[$accCol] = $data[$accCol];
                }
            }

            if (!empty($productData)) {
                $this->writeRepository->updateProductRow($productId, $productData);
            }

            if (array_key_exists('media', $data)) {
                $this->writeRepository->deleteProductLevelMedia($productId);

                if (!empty($data['media'])) {
                    $this->writeRepository->insertMedia(array_map(
                        fn ($m) => $this->buildMediaRow($m, $productId, null),
                        $data['media']
                    ));
                }
            }

            if (array_key_exists('variation_types', $data)) {

                $this->syncVariantStructure($productId, $data);
            } elseif (!empty($data['variants'])) {
                foreach ($data['variants'] as $variant) {
                    if (empty($variant['sku'])) continue;

                    $variantData = Arr::only($variant, [
                        'sell_price', 'buy_price', 'barcode', 'is_active',
                        'sales_tax_id', 'purchase_tax_id', 'min_stock', 'safe_stock',
                        'weight',
                    ]);
                    if (array_key_exists('weight', $variantData) && $variantData['weight'] === null) {
                        unset($variantData['weight']);
                    }
                    if (!empty($variant['sales_tax_id'])) {
                        $variantData['tax_rate'] = $this->taxRate($variant['sales_tax_id']);
                    }
                    $variantData['updated_at'] = now();

                    $existingVariant = $this->writeRepository->findVariantByProductAndSku($productId, $variant['sku']);

                    if ($existingVariant) {
                        $this->writeRepository->updateVariantRow($existingVariant->id, $variantData);
                        $variantId = $existingVariant->id;
                    } else {
                        $this->writeRepository->insertVariantRow(array_merge($variantData, [
                            'id' => \Ramsey\Uuid\Uuid::uuid7()->toString(),
                            'product_id' => $productId,
                            'sku' => $variant['sku'],
                            'created_at' => now(),
                        ]));
                        $variantId = $this->writeRepository->variantIdByProductAndSku($productId, $variant['sku']);
                    }

                    if (array_key_exists('media', $variant)) {
                        $this->writeRepository->deleteVariantMedia($variantId);

                        if (!empty($variant['media'])) {
                            $this->writeRepository->insertMedia(array_map(
                                fn ($m) => $this->buildMediaRow($m, $productId, $variantId),
                                $variant['media']
                            ));
                        }
                    }

                    if (array_key_exists('unlimited_shop_ids', $variant)) {
                        $this->writeRepository->deleteUnlimitedShops($variantId);
                        if (!empty($variant['unlimited_shop_ids'])) {
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
            foreach ($opts as $o) { $p[(int) $o['attribute_id']] = $this->normalizeOptionValue((string) $o['value']); }
            ksort($p);
            return implode('|', array_map(static fn ($k, $v) => "{$k}:{$v}", array_keys($p), $p));
        };
        $keyOfSet = function (array $set): string {
            $p = [];
            foreach ($set as $a => $v) { $p[(int) $a] = $this->normalizeOptionValue((string) $v); }
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
            throw new DomainException('SKU varian sudah digunakan produk lain: ' . implode(', ', $foreignSkus));
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
                    'Opsi varian tidak bisa dihapus karena varian berikut masih punya stok: ' . implode(', ', $blocked)
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
            $variantId = \Ramsey\Uuid\Uuid::uuid7()->toString();
            $this->writeRepository->insertVariantRow(array_merge(
                $this->variantUpdatableFields($v),
                [
                    'id' => $variantId,
                    'product_id' => $productId,
                    'sku' => $v['sku'] ?? $this->generateVariantSku($productId, $opts),
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
        if (array_key_exists('weight', $f) && $f['weight'] === null) {
            unset($f['weight']);
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
        foreach ($opts as $o) { $combo[(int) $o['attribute_id']] = $this->normalizeOptionValue((string) $o['value']); }

        foreach ($activeVariants as $av) {
            $set = $setByVariant[$av->id] ?? [];
            if (empty($set)) { continue; }
            $subset = true;
            foreach ($set as $a => $val) {
                if (($combo[(int) $a] ?? null) !== $this->normalizeOptionValue((string) $val)) { $subset = false; break; }
            }
            if ($subset) { return (float) $av->sell_price; }
        }

        return null;
    }

    private function generateVariantSku(string $productId, array $opts): string
    {
        $base = $this->writeRepository->productSku($productId) ?: ('PRD-' . substr($productId, 0, 8));
        $parts = [$base];
        foreach ($opts as $o) {
            $parts[] = preg_replace('/[^A-Za-z0-9]+/', '-', (string) $o['value']);
        }
        $sku = strtoupper(trim(implode('-', $parts), '-'));

        $candidate = $sku;
        $i = 1;
        while ($this->writeRepository->variantSkuExists($candidate)) {
            $candidate = $sku . '-' . (++$i);
        }

        return $candidate;
    }

    private function syncSpecifications(string $productId, array $specs): void
    {
        $this->writeRepository->deleteSpecifications($productId);
        if (empty($specs)) { return; }

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
                ->map(fn ($o) => $o['attribute_id'] . ':' . $this->normalizeOptionValue((string) $o['value']))
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
            if (!empty($vt['attribute_id'])) continue;
            $name = $vt['name'] ?? null;
            if (!$name) continue;

            if (!isset($nameToId[$name])) {
                $attr = Attribute::create(['name' => $name, 'type' => 'sales']);
                $nameToId[$name] = $attr->id;
            }
            $data['variation_types'][$i]['attribute_id'] = $nameToId[$name];
        }

        foreach ($data['variants'] ?? [] as $vi => $variant) {
            foreach ($variant['options'] ?? [] as $oi => $opt) {
                if (!empty($opt['attribute_id'])) continue;
                $name = $opt['name'] ?? null;
                if (!$name) continue;

                if (!isset($nameToId[$name])) {
                    $attr = Attribute::create(['name' => $name, 'type' => 'sales']);
                    $nameToId[$name] = $attr->id;
                }
                $data['variants'][$vi]['options'][$oi]['attribute_id'] = $nameToId[$name];
            }
        }
    }

    public function createProduct(array $data, ?array &$variantIds = null)
    {
        $this->resolveCustomAttributes($data);
        $this->assertVariationConstraints($data);
        $this->assertCategoryAttributes($data['category_id'] ?? null, $data, true);
        $variantIds = [];

        return DB::transaction(function () use ($data, &$variantIds) {
            $productData = Arr::only($data, [
                'category_id', 'name', 'sku', 'description',
                'order_type', 'indent_days',
                'weight', 'weight_unit', 'length', 'width', 'height', 'is_active',
                'is_bundle', 'is_consignment', 'is_from_channel',
                'is_stored', 'is_sold', 'is_purchased',
                'purchase_lead_time', 'package_contents',
            ]);

            $productData['status'] = $data['status'] ?? Product::STATUS_MASTER;
            $productData['verified_at'] = $data['verified_at'] ?? null;

            $productData['sales_account_id'] = $this->resolveAccountId($data['sales_account_id'] ?? null, AccountMappingKey::SALES_REVENUE);
            $productData['sales_return_account_id'] = $this->resolveAccountId($data['sales_return_account_id'] ?? null, AccountMappingKey::SALES_RETURN);
            $productData['inventory_account_id'] = $this->resolveAccountId($data['inventory_account_id'] ?? null, AccountMappingKey::INVENTORY);
            $productData['cogs_account_id'] = $this->resolveAccountId($data['cogs_account_id'] ?? null, AccountMappingKey::COGS);

            $productId = \Ramsey\Uuid\Uuid::uuid7()->toString();
            $this->writeRepository->insertProductRow(array_merge($productData, [
                'id' => $productId,
                'created_at' => now(),
                'updated_at' => now(),
            ]));

            if (!empty($data['specifications'])) {
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

            if (!empty($data['media'])) {
                $media = array_map(
                    fn ($m) => $this->buildMediaRow($m, $productId, null),
                    $data['media']
                );
                $this->writeRepository->insertMedia($media);
            }

            if (!empty($data['variation_types'])) {
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

            if (!empty($data['variants'])) {
                foreach ($data['variants'] as $variant) {
                    $variantData = Arr::only($variant, [
                        'sku', 'barcode', 'buy_price', 'sell_price', 'is_active',
                        'sales_tax_id', 'purchase_tax_id', 'min_stock', 'safe_stock',
                        'weight', 'length', 'width', 'height',
                    ]);

                    if (!empty($variant['sales_tax_id'])) {
                        $variantData['tax_rate'] = $this->taxRate($variant['sales_tax_id']);
                    }

                    $variantId = \Ramsey\Uuid\Uuid::uuid7()->toString();
                    $variantIds[] = $variantId;
                    $this->writeRepository->insertVariantRow(array_merge($variantData, [
                        'id' => $variantId,
                        'product_id' => $productId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]));

                    if (!empty($variant['unlimited_shop_ids'])) {
                        $this->writeRepository->syncUnlimitedShops($variantId, $variant['unlimited_shop_ids']);
                    }

                    if (!empty($variant['options'])) {
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

                    if (!empty($variant['media'])) {
                        $vMedia = array_map(
                            fn ($m) => $this->buildMediaRow($m, $productId, $variantId),
                            $variant['media']
                        );
                        $this->writeRepository->insertMedia($vMedia);
                    }

                    if (!empty($variant['wholesale_prices'])) {
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
