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

            if (array_key_exists('variation_types', $data)) {
                // Edit terstruktur (Fase D): immutability jenis/opsi + ekspansi kombinasi.
                $this->syncVariantStructure($productId, $data);
            } elseif (!empty($data['variants'])) {
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

            if (array_key_exists('specifications', $data)) {
                $this->syncSpecifications($productId, $data['specifications'] ?? []);
            }

            return $productId;
        });
    }

    /**
     * Edit varian terstruktur (Fase D): immutability jenis/nilai-opsi + ekspansi kombinasi.
     * Jenis/nilai lama tak boleh dihapus; tambah nilai → regenerasi cartesian. Varian lama yang
     * bukan kombinasi sah → soft-deprecate (is_active=false, superseded_at), TIDAK dihapus.
     */
    private function syncVariantStructure(string $productId, array $data): void
    {
        $types = $data['variation_types'] ?? [];
        $payloadVariants = $data['variants'] ?? [];

        $existingTypes = DB::table('product_variation_types')
            ->where('product_id', $productId)->pluck('attribute_id')
            ->map(fn ($v) => (int) $v)->all();

        $activeVariants = DB::table('product_variants')
            ->where('product_id', $productId)->where('is_active', true)
            ->get(['id', 'sku', 'sell_price']);

        $optRows = DB::table('variant_options')
            ->whereIn('variant_id', $activeVariants->pluck('id')->all() ?: ['00000000-0000-0000-0000-000000000000'])
            ->get(['variant_id', 'attribute_id', 'value']);

        $setByVariant = [];   // [variant_id => [attrId => value]]
        $existingValues = []; // [attrId => [lowerVal => origVal]]
        foreach ($optRows as $o) {
            $setByVariant[$o->variant_id][(int) $o->attribute_id] = (string) $o->value;
            $existingValues[(int) $o->attribute_id][mb_strtolower((string) $o->value)] = (string) $o->value;
        }

        $payloadTypes = array_map(static fn ($t) => (int) $t['attribute_id'], $types);
        $payloadValues = [];
        foreach ($payloadVariants as $v) {
            foreach ($v['options'] ?? [] as $opt) {
                $payloadValues[(int) $opt['attribute_id']][mb_strtolower((string) $opt['value'])] = true;
            }
        }

        // Immutability: jenis & nilai-opsi lama tak boleh hilang.
        foreach ($existingTypes as $et) {
            if (! in_array($et, $payloadTypes, true)) {
                throw new DomainException('Jenis varian yang sudah tersimpan tidak boleh dihapus.');
            }
        }
        foreach ($existingValues as $attrId => $vals) {
            foreach ($vals as $lk => $orig) {
                if (! isset($payloadValues[$attrId][$lk])) {
                    throw new DomainException("Opsi varian '{$orig}' yang sudah tersimpan tidak boleh dihapus.");
                }
            }
        }

        $this->assertVariationConstraints($data);

        $keyOfOpts = static function (array $opts): string {
            $p = [];
            foreach ($opts as $o) { $p[(int) $o['attribute_id']] = mb_strtolower((string) $o['value']); }
            ksort($p);
            return implode('|', array_map(static fn ($k, $v) => "{$k}:{$v}", array_keys($p), $p));
        };
        $keyOfSet = static function (array $set): string {
            $p = [];
            foreach ($set as $a => $v) { $p[(int) $a] = mb_strtolower((string) $v); }
            ksort($p);
            return implode('|', array_map(static fn ($k, $v) => "{$k}:{$v}", array_keys($p), $p));
        };

        $existingByKey = [];
        foreach ($activeVariants as $av) {
            $existingByKey[$keyOfSet($setByVariant[$av->id] ?? [])] = $av;
        }

        $desired = [];
        foreach ($payloadVariants as $v) {
            $opts = $v['options'] ?? [];
            $key = $keyOfOpts($opts);
            $desired[$key] = true;

            if (isset($existingByKey[$key])) {
                $upd = $this->variantUpdatableFields($v);
                if ($upd) {
                    $upd['updated_at'] = now();
                    DB::table('product_variants')->where('id', $existingByKey[$key]->id)->update($upd);
                }
                continue;
            }

            // Kombinasi baru → harga warisan dari leluhur (default), stok 0, mapping fresh.
            $price = $v['sell_price'] ?? $this->ancestorPrice($opts, $activeVariants, $setByVariant);
            $variantId = \Ramsey\Uuid\Uuid::uuid7()->toString();
            DB::table('product_variants')->insert(array_merge(
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
                DB::table('variant_options')->insert([
                    'variant_id' => $variantId,
                    'attribute_id' => (int) $o['attribute_id'],
                    'value' => (string) $o['value'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Soft-deprecate varian aktif yang bukan kombinasi sah (JANGAN hapus).
        foreach ($existingByKey as $key => $av) {
            if (! isset($desired[$key])) {
                DB::table('product_variants')->where('id', $av->id)
                    ->update(['is_active' => false, 'superseded_at' => now(), 'updated_at' => now()]);
            }
        }

        // Tambah jenis varian baru (tak pernah hapus).
        foreach ($types as $t) {
            $attrId = (int) $t['attribute_id'];
            $exists = DB::table('product_variation_types')
                ->where('product_id', $productId)->where('attribute_id', $attrId)->exists();
            if (! $exists) {
                DB::table('product_variation_types')->insert([
                    'product_id' => $productId,
                    'attribute_id' => $attrId,
                    'sort_order' => $t['sort_order'] ?? 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /** Field varian yang boleh di-update/insert (selain id/product_id/options). */
    private function variantUpdatableFields(array $v): array
    {
        $f = Arr::only($v, [
            'buy_price', 'barcode', 'is_active',
            'sales_tax_id', 'purchase_tax_id', 'min_stock', 'safe_stock',
        ]);
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

    /** Harga default kombinasi baru = harga varian leluhur (option-set ⊂ kombinasi). */
    private function ancestorPrice(array $opts, $activeVariants, array $setByVariant): ?float
    {
        $combo = [];
        foreach ($opts as $o) { $combo[(int) $o['attribute_id']] = mb_strtolower((string) $o['value']); }

        foreach ($activeVariants as $av) {
            $set = $setByVariant[$av->id] ?? [];
            if (empty($set)) { continue; }
            $subset = true;
            foreach ($set as $a => $val) {
                if (($combo[(int) $a] ?? null) !== mb_strtolower((string) $val)) { $subset = false; break; }
            }
            if ($subset) { return (float) $av->sell_price; }
        }

        return null;
    }

    /** SKU saran (fallback bila FE tak kirim): base + kode opsi ter-sanitasi, dijamin unik. */
    private function generateVariantSku(string $productId, array $opts): string
    {
        $base = DB::table('products')->where('id', $productId)->value('sku') ?: ('PRD-' . substr($productId, 0, 8));
        $parts = [$base];
        foreach ($opts as $o) {
            $parts[] = preg_replace('/[^A-Za-z0-9]+/', '-', (string) $o['value']);
        }
        $sku = strtoupper(trim(implode('-', $parts), '-'));

        $candidate = $sku;
        $i = 1;
        while (DB::table('product_variants')->where('sku', $candidate)->exists()) {
            $candidate = $sku . '-' . (++$i);
        }

        return $candidate;
    }

    /** Spesifikasi: replace-all (tidak diatur immutability). */
    private function syncSpecifications(string $productId, array $specs): void
    {
        DB::table('product_specifications')->where('product_id', $productId)->delete();
        if (empty($specs)) { return; }

        DB::table('product_specifications')->insert(array_map(static fn ($s) => [
            'product_id' => $productId,
            'attribute_id' => $s['attribute_id'],
            'attribute_option_id' => $s['attribute_option_id'] ?? null,
            'text_value' => $s['text_value'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $specs));
    }

    /**
     * Invariant varian (selaras FormRequest + unique DB):
     *  - maks. 2 jenis varian per produk;
     *  - tiap jenis (attribute_id) unik;
     *  - opsi varian hanya boleh memakai jenis yang dideklarasikan di variation_types;
     *  - kombinasi opsi antar varian tidak boleh duplikat.
     */
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

            // Kunci kombinasi distabilkan dengan mengurutkan berdasarkan attribute_id.
            $combo = collect($options)
                ->sortBy('attribute_id')
                ->map(static fn ($o) => $o['attribute_id'] . ':' . $o['value'])
                ->implode('|');

            if ($combo !== '' && isset($seenCombos[$combo])) {
                throw new DomainException('Kombinasi varian tidak boleh sama dengan varian lain.');
            }
            $seenCombos[$combo] = true;
        }
    }

    public function createProduct(array $data)
    {
        $this->assertVariationConstraints($data);

        return DB::transaction(function () use ($data) {
            $productData = Arr::only($data, [
                'category_id', 'brand_id', 'name', 'sku', 'description',
                'order_type', 'indent_days',
                'weight', 'length', 'width', 'height', 'is_active',
                'is_bundle', 'is_consignment',
                'is_stored', 'is_sold', 'is_purchased',
                'purchase_lead_time', 'package_contents',
            ]);

            // Tanpa review internal: produk langsung Master (default).
            // "Simpan draf" kirim status 'download'.
            $productData['status'] = $data['status'] ?? Product::STATUS_MASTER;

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
