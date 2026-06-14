<?php

namespace Modules\Product\Services;

use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Modules\Channel\Models\ChannelShop;
use Modules\Finance\Support\AccountMappingKey;
use Modules\Inventory\Models\Inventory;
use Modules\Product\Models\Product;
use Modules\Product\Repositories\ProductRepository;

class ProductService
{

    private const DETAIL_RELATIONS = [
        'variants.channelMappings.channelMapping',
        'variants.inventories',
        'media',
        'category',
        'brand',
        'channelMappings.channelShop.channel',
    ];

    public function __construct(
        private readonly ProductRepository $repository,
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

    /**
     * Akun default produk: pakai yang dikirim, atau fallback ke akun default
     * organisasi (account_mappings). Kembalikan null bila dua-duanya kosong.
     */
    private function resolveAccountId(?string $given, string $mappingKey): ?string
    {
        if (! empty($given)) {
            return $given;
        }

        return DB::table('account_mappings')->where('key', $mappingKey)->value('account_id');
    }

    /** Ambil rate pajak (cache ke product_variants.tax_rate). */
    private function taxRate($taxId): float
    {
        return (float) (DB::table('taxes')->where('id', $taxId)->value('rate') ?? 0);
    }

    /** Sisipkan baris pivot toko "stok tak terbatas" untuk satu varian. */
    private function syncUnlimitedShops(string $variantId, array $shopIds): void
    {
        $rows = [];
        foreach (array_unique($shopIds) as $shopId) {
            $rows[] = [
                'id' => \Ramsey\Uuid\Uuid::uuid7()->toString(),
                'variant_id' => $variantId,
                'channel_shop_id' => $shopId,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        if ($rows) {
            DB::table('variant_unlimited_shops')->insert($rows);
        }
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
        $attributes = [
            'name' => $data['name'],
            'sku' => $data['sku'] ?? null,
            'category_id' => $data['category_id'],
            'brand_id' => $data['brand_id'] ?? null,
            'is_bundle' => true,
        ];

        return $this->repository->saveBundle($data['id'] ?? null, $attributes, $data['components']);
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

        $product->delete();
    }

    public function upsertFromChannel(array $data)
    {
        $sku = $data['sku'] ?? ($data['variants'][0]['sku'] ?? null);
        if ($sku) {
            $existingProduct = DB::table('products')->where('sku', $sku)->first();
            $productId = $existingProduct ? $existingProduct->id : null;

            if (!$productId) {
                $variant = DB::table('product_variants')->where('sku', $sku)->first();
                if ($variant) {
                    $productId = $variant->product_id;
                }
            }

            if ($productId) {
                return $this->updateProduct($productId, $data);
            }
        }

        return $this->createProduct($data);
    }

    public function updateProduct(string $productId, array $data)
    {
        return DB::transaction(function () use ($productId, $data) {
            $productData = Arr::only($data, [
                'name', 'sku', 'description', 'category_id', 'brand_id', 'search_keyword',
                'order_type', 'indent_days', 'condition', 'status',
                'weight', 'length', 'width', 'height', 'is_active', 'is_cod_allowed',
                'is_bundle', 'is_consignment', 'package_contents',
                'is_stored', 'is_sold', 'is_purchased', 'purchase_lead_time',
            ]);

            // Akun hanya di-update bila field-nya memang dikirim (boleh null = lepas akun).
            foreach ([
                'sales_account_id', 'sales_return_account_id',
                'inventory_account_id', 'cogs_account_id',
            ] as $accCol) {
                if (array_key_exists($accCol, $data)) {
                    $productData[$accCol] = $data[$accCol];
                }
            }

            if (!empty($productData)) {
                $productData['updated_at'] = now();
                DB::table('products')->where('id', $productId)->update($productData);
            }

            if (array_key_exists('media', $data)) {
                DB::table('product_media')
                    ->where('product_id', $productId)
                    ->whereNull('variant_id')
                    ->delete();

                if (!empty($data['media'])) {
                    DB::table('product_media')->insert(array_map(
                        fn ($m) => $this->buildMediaRow($m, $productId, null),
                        $data['media']
                    ));
                }
            }

            if (!empty($data['variants'])) {
                foreach ($data['variants'] as $variant) {
                    if (empty($variant['sku'])) continue;

                    $variantData = Arr::only($variant, [
                        'sell_price', 'buy_price', 'barcode', 'is_active',
                        'sales_tax_id', 'purchase_tax_id', 'min_stock', 'safe_stock',
                    ]);
                    if (!empty($variant['sales_tax_id'])) {
                        $variantData['tax_rate'] = $this->taxRate($variant['sales_tax_id']);
                    }
                    $variantData['updated_at'] = now();

                    $existingVariant = DB::table('product_variants')
                        ->where('product_id', $productId)
                        ->where('sku', $variant['sku'])
                        ->first();

                    if ($existingVariant) {
                        DB::table('product_variants')->where('id', $existingVariant->id)->update($variantData);
                        $variantId = $existingVariant->id;
                    } else {
                        DB::table('product_variants')->insert(array_merge($variantData, [
                            'id' => \Ramsey\Uuid\Uuid::uuid7()->toString(),
                            'product_id' => $productId,
                            'sku' => $variant['sku'],
                            'created_at' => now(),
                        ]));
                        $variantId = DB::table('product_variants')->where('product_id', $productId)->where('sku', $variant['sku'])->value('id');
                    }

                    if (array_key_exists('media', $variant)) {
                        DB::table('product_media')->where('variant_id', $variantId)->delete();

                        if (!empty($variant['media'])) {
                            DB::table('product_media')->insert(array_map(
                                fn ($m) => $this->buildMediaRow($m, $productId, $variantId),
                                $variant['media']
                            ));
                        }
                    }

                    if (!empty($variant['channel_prices'])) {
                        foreach ($variant['channel_prices'] as $cp) {
                            $channelShopId = $cp['channel_shop_id'];

                            $pcm = DB::table('product_channel_mappings')
                                ->where('product_id', $productId)
                                ->where('channel_shop_id', $channelShopId)
                                ->first();

                            if (!$pcm) {
                                $pcmId = \Ramsey\Uuid\Uuid::uuid7()->getHex()->toString();
                                DB::table('product_channel_mappings')->insert([
                                    'id' => $pcmId,
                                    'product_id' => $productId,
                                    'channel_shop_id' => $channelShopId,
                                    'sync_status' => 'pending',
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                            } else {
                                $pcmId = $pcm->id;
                            }

                            $pvcm = DB::table('product_variant_channel_mappings')
                                ->where('product_channel_mapping_id', $pcmId)
                                ->where('variant_id', $variantId)
                                ->first();

                            if ($pvcm) {
                                DB::table('product_variant_channel_mappings')
                                    ->where('id', $pvcm->id)
                                    ->update([
                                        'override_price' => $cp['price'],
                                        'updated_at' => now(),
                                    ]);
                            } else {
                                DB::table('product_variant_channel_mappings')->insert([
                                    'id' => \Ramsey\Uuid\Uuid::uuid7()->getHex()->toString(),
                                    'product_channel_mapping_id' => $pcmId,
                                    'variant_id' => $variantId,
                                    'override_price' => $cp['price'],
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                            }
                        }
                    }

                    if (array_key_exists('unlimited_shop_ids', $variant)) {
                        DB::table('variant_unlimited_shops')->where('variant_id', $variantId)->delete();
                        if (!empty($variant['unlimited_shop_ids'])) {
                            $this->syncUnlimitedShops($variantId, $variant['unlimited_shop_ids']);
                        }
                    }
                }
            }

            return $productId;
        });
    }

    public function createProduct(array $data)
    {
        return DB::transaction(function () use ($data) {
            $productData = Arr::only($data, [
                'category_id', 'brand_id', 'name', 'sku', 'description',
                'order_type', 'indent_days',
                'weight', 'length', 'width', 'height', 'is_active',
                'is_bundle', 'is_consignment',
                'is_stored', 'is_sold', 'is_purchased',
                'purchase_lead_time', 'package_contents',
            ]);

            // "Simpan" → in_review (default). Master HANYA lewat approve.
            $productData['status'] = $data['status'] ?? Product::STATUS_IN_REVIEW;

            // Akun: pakai input atau fallback ke mapping default organisasi.
            $productData['sales_account_id'] = $this->resolveAccountId($data['sales_account_id'] ?? null, AccountMappingKey::SALES_REVENUE);
            $productData['sales_return_account_id'] = $this->resolveAccountId($data['sales_return_account_id'] ?? null, AccountMappingKey::SALES_RETURN);
            $productData['inventory_account_id'] = $this->resolveAccountId($data['inventory_account_id'] ?? null, AccountMappingKey::INVENTORY);
            $productData['cogs_account_id'] = $this->resolveAccountId($data['cogs_account_id'] ?? null, AccountMappingKey::COGS);

            $productId = \Ramsey\Uuid\Uuid::uuid7()->toString();
            DB::table('products')->insert(array_merge($productData, [
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
                DB::table('product_specifications')->insert($specs);
            }

            if (!empty($data['media'])) {
                $media = array_map(
                    fn ($m) => $this->buildMediaRow($m, $productId, null),
                    $data['media']
                );
                DB::table('product_media')->insert($media);
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
                DB::table('product_variation_types')->insert($varTypes);
            }

            if (!empty($data['variants'])) {
                foreach ($data['variants'] as $variant) {
                    $variantData = Arr::only($variant, [
                        'sku', 'barcode', 'buy_price', 'sell_price', 'is_active',
                        'sales_tax_id', 'purchase_tax_id', 'min_stock', 'safe_stock',
                    ]);

                    // Cache rate pajak penjualan ke tax_rate (dibaca MasterItemResource).
                    if (!empty($variant['sales_tax_id'])) {
                        $variantData['tax_rate'] = $this->taxRate($variant['sales_tax_id']);
                    }

                    $variantId = \Ramsey\Uuid\Uuid::uuid7()->toString();
                    DB::table('product_variants')->insert(array_merge($variantData, [
                        'id' => $variantId,
                        'product_id' => $productId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]));

                    if (!empty($variant['unlimited_shop_ids'])) {
                        $this->syncUnlimitedShops($variantId, $variant['unlimited_shop_ids']);
                    }

                    if (!empty($variant['options'])) {
                        $options = array_map(function ($opt) use ($variantId) {
                            return [
                                'variant_id' => $variantId,
                                'attribute_id' => $opt['attribute_id'],
                                'value' => $opt['value'],
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];
                        }, $variant['options']);
                        DB::table('variant_options')->insert($options);
                    }

                    if (!empty($variant['media'])) {
                        $vMedia = array_map(
                            fn ($m) => $this->buildMediaRow($m, $productId, $variantId),
                            $variant['media']
                        );
                        DB::table('product_media')->insert($vMedia);
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
                        DB::table('product_wholesale_prices')->insert($wholesales);
                    }

                    if (!empty($variant['channel_prices'])) {
                        foreach ($variant['channel_prices'] as $cp) {
                            $channelShopId = $cp['channel_shop_id'];

                            $pcm = DB::table('product_channel_mappings')
                                ->where('product_id', $productId)
                                ->where('channel_shop_id', $channelShopId)
                                ->first();

                            if (!$pcm) {
                                $pcmId = \Ramsey\Uuid\Uuid::uuid7()->getHex()->toString();
                                DB::table('product_channel_mappings')->insert([
                                    'id' => $pcmId,
                                    'product_id' => $productId,
                                    'channel_shop_id' => $channelShopId,
                                    'sync_status' => 'pending',
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                            } else {
                                $pcmId = $pcm->id;
                            }

                            DB::table('product_variant_channel_mappings')->insert([
                                'id' => \Ramsey\Uuid\Uuid::uuid7()->getHex()->toString(),
                                'product_channel_mapping_id' => $pcmId,
                                'variant_id' => $variantId,
                                'override_price' => $cp['price'],
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                    }
                }
            }

            return $productId;
        });
    }
}
