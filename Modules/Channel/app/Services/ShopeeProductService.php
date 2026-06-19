<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\DB;
use Modules\Channel\Exceptions\TokenExpiredException;
use Modules\Channel\Repositories\ChannelShopRepository;
use Ramsey\Uuid\Uuid;

/**
 * Sinkronisasi metadata produk Shopee dari Shopee Open API v2:
 * - kategori (get_category) → channel_categories
 * - atribut per kategori (get_attributes) → channel_attributes (+ options)
 * - varian/model per item (get_model_list)
 *
 * Pola mengikuti TikTok/Lazada (channel_categories/channel_attributes + updateOrCreate).
 */
class ShopeeProductService
{
    public function __construct(
        protected ShopeeClient $client,
        protected ChannelShopRepository $shopRepository,
        protected ShopeeAuthService $authService,
    ) {}

    /** Tarik seluruh pohon kategori Shopee → channel_categories. */
    public function syncCategoryTree(string $shopId): int
    {
        $shop = $this->requireShop($shopId);
        $channelId = $this->channelId();

        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('GET', '/api/v2/product/get_category', ['language' => 'id'], $token, $shop->shop_id));

        $list = $res['response']['category_list'] ?? [];

        $count = 0;
        $seen = [];

        foreach ($list as $node) {
            $extId = (string) ($node['category_id'] ?? '');
            if ($extId === '') {
                continue;
            }
            $seen[] = $extId;

            $values = [
                'parent_external_id' => (string) ($node['parent_category_id'] ?? '0'),
                'name' => (string) ($node['display_category_name'] ?? $node['original_category_name'] ?? ''),
                'is_leaf' => ! (bool) ($node['has_children'] ?? false),
                'updated_at' => now(),
            ];

            $existing = DB::table('channel_categories')
                ->where('channel_id', $channelId)
                ->where('external_id', $extId)
                ->first();

            if ($existing) {
                DB::table('channel_categories')->where('id', $existing->id)->update($values);
            } else {
                DB::table('channel_categories')->insert($values + [
                    'id' => Uuid::uuid7()->toString(),
                    'channel_id' => $channelId,
                    'external_id' => $extId,
                    'created_at' => now(),
                ]);
            }
            $count++;
        }

        $this->sweepDeprecated($channelId, $seen);

        return $count;
    }

    /** Tarik atribut untuk satu kategori Shopee → channel_attributes (+ options). */
    public function syncCategoryAttributes(string $shopId, string $categoryExtId): int
    {
        $shop = $this->requireShop($shopId);
        $channelId = $this->channelId();

        $channelCategory = DB::table('channel_categories')
            ->where('channel_id', $channelId)
            ->where('external_id', $categoryExtId)
            ->first();

        if (! $channelCategory) {
            throw new \Exception("Kategori Shopee {$categoryExtId} belum disinkronkan (jalankan sinkron kategori dulu).");
        }

        $params = ['category_id' => (int) $categoryExtId, 'language' => 'id'];
        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('GET', '/api/v2/product/get_attributes', $params, $token, $shop->shop_id));

        $attributes = $res['response']['attribute_list'] ?? [];
        $count = 0;

        foreach ($attributes as $attr) {
            $extId = (string) ($attr['attribute_id'] ?? '');
            if ($extId === '') {
                continue;
            }

            $inputType = strtolower((string) ($attr['input_validation_type'] ?? ''));

            $values = [
                'name' => (string) ($attr['original_attribute_name'] ?? $attr['display_attribute_name'] ?? ''),
                'is_required' => (bool) ($attr['is_mandatory'] ?? false),
                'is_multiple' => in_array($inputType, ['multiple_select', 'multiple_selection_combo_box'], true)
                    || (strtolower((string) ($attr['format_type'] ?? '')) === 'qualitative' && ($attr['is_multiple'] ?? false)),
                'is_sale_prop' => false, // Shopee: variasi memakai tier_variation, bukan atribut.
                'updated_at' => now(),
            ];

            $existing = DB::table('channel_attributes')
                ->where('channel_category_id', $channelCategory->id)
                ->where('external_id', $extId)
                ->first();

            if ($existing) {
                DB::table('channel_attributes')->where('id', $existing->id)->update($values);
                $channelAttributeId = $existing->id;
            } else {
                $channelAttributeId = Uuid::uuid7()->toString();
                DB::table('channel_attributes')->insert($values + [
                    'id' => $channelAttributeId,
                    'channel_category_id' => $channelCategory->id,
                    'external_id' => $extId,
                    'created_at' => now(),
                ]);
            }
            $count++;

            foreach ($attr['attribute_value_list'] ?? [] as $opt) {
                $optExtId = (string) ($opt['value_id'] ?? '');
                if ($optExtId === '') {
                    continue;
                }

                $optValues = [
                    'name' => (string) ($opt['original_value_name'] ?? $opt['display_value_name'] ?? $optExtId),
                    'updated_at' => now(),
                ];

                $existingOpt = DB::table('channel_attribute_options')
                    ->where('channel_attribute_id', $channelAttributeId)
                    ->where('external_id', $optExtId)
                    ->first();

                if ($existingOpt) {
                    DB::table('channel_attribute_options')->where('id', $existingOpt->id)->update($optValues);
                } else {
                    DB::table('channel_attribute_options')->insert($optValues + [
                        'id' => Uuid::uuid7()->toString(),
                        'channel_attribute_id' => $channelAttributeId,
                        'external_id' => $optExtId,
                        'created_at' => now(),
                    ]);
                }
            }
        }

        return $count;
    }

    /** Sinkron atribut untuk semua kategori Shopee yang sudah dipetakan. */
    public function syncAllMappedCategoryAttributes(string $shopId): array
    {
        $channelId = $this->channelId();

        $leaves = DB::table('category_channel_mappings as m')
            ->join('channel_categories as c', 'c.id', '=', 'm.channel_category_id')
            ->where('c.channel_id', $channelId)
            ->distinct()
            ->pluck('c.external_id');

        $result = [];
        foreach ($leaves as $extId) {
            $result[(string) $extId] = $this->syncCategoryAttributes($shopId, (string) $extId);
        }

        return $result;
    }

    /** Ambil daftar varian/model sebuah item Shopee (get_model_list). */
    public function getModelList(string $shopId, string $itemId): array
    {
        $shop = $this->requireShop($shopId);

        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('GET', '/api/v2/product/get_model_list', ['item_id' => (int) $itemId], $token, $shop->shop_id));

        return [
            'tier_variation' => $res['response']['tier_variation'] ?? [],
            'models' => $res['response']['model'] ?? [],
        ];
    }

    protected function sweepDeprecated(string $channelId, array $seen): void
    {
        if (empty($seen)) {
            return;
        }

        DB::table('channel_categories')
            ->where('channel_id', $channelId)
            ->whereNotIn('external_id', $seen)
            ->whereNull('deprecated_at')
            ->update(['deprecated_at' => now(), 'updated_at' => now()]);

        DB::table('channel_categories')
            ->where('channel_id', $channelId)
            ->whereIn('external_id', $seen)
            ->whereNotNull('deprecated_at')
            ->update(['deprecated_at' => null, 'updated_at' => now()]);
    }

    protected function channelId(): string
    {
        $channelId = DB::table('channels')->where('code', 'shopee')->value('id');
        if (! $channelId) {
            throw new \Exception('Channel shopee belum ter-seed.');
        }

        return $channelId;
    }

    protected function callWithRefresh(object $shop, callable $fn): array
    {
        try {
            return $fn($shop->access_token);
        } catch (TokenExpiredException $e) {
            $this->authService->refreshStoreToken((string) $shop->id);
            $fresh = $this->shopRepository->findByShopId($shop->shop_id);

            return $fn($fresh->access_token);
        }
    }

    protected function requireShop(string $shopId): object
    {
        $shop = $this->shopRepository->findByShopId($shopId);

        if (! $shop || ! $shop->access_token) {
            throw new \Exception("Toko Shopee tidak terhubung: {$shopId}");
        }

        return $shop;
    }
}
