<?php

namespace Modules\Product\Services;

use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Modules\Channel\Models\ChannelShop;
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

    /**
     * Bangun satu baris product_media. Bila media_uuid diberikan, url di-resolve
     * dari media library (snapshot disimpan agar reader lama tetap pakai ->url).
     */
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
                'name', 'description', 'weight', 'length', 'width', 'height', 'is_active',
                'is_bundle', 'is_consignment',
            ]);

            if (!empty($productData)) {
                $productData['updated_at'] = now();
                DB::table('products')->where('id', $productId)->update($productData);
            }

            // Replace koleksi media level produk (variant_id NULL) bila `media` dikirim.
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
                        'sell_price', 'is_active'
                    ]);
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

                    // Replace media varian bila `media` dikirim untuk varian ini.
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
                }
            }

            return $productId;
        });
    }

    public function createProduct(array $data)
    {
        return DB::transaction(function () use ($data) {
            $productData = Arr::only($data, [
                'category_id', 'brand_id', 'name', 'description',
                'weight', 'length', 'width', 'height', 'is_active',
                'status', 'is_bundle', 'is_consignment',
            ]);

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
                        'sku', 'sell_price', 'is_active'
                    ]);

                    $variantId = \Ramsey\Uuid\Uuid::uuid7()->toString();
                    DB::table('product_variants')->insert(array_merge($variantData, [
                        'id' => $variantId,
                        'product_id' => $productId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]));

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
