<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\DB;

class LazadaProductMapper
{

    public function map(array $product, array $imageUrls = [], array $config = []): array
    {
        $skus = [];
        $channelCategoryUuid = $this->resolveChannelCategoryUuid($product['category_id'] ?? null);

        foreach ($product['variants'] ?? [] as $variant) {
            if (empty($variant['sku'])) {
                continue;
            }

            // Varian yang di-supersede (Fase D) tidak di-listing ke channel.
            if (array_key_exists('is_active', $variant) && ! $variant['is_active']) {
                continue;
            }

            // Sale-property varian (mis. Warna/Ukuran) → key & nilai versi channel.
            $saleProps = $this->buildSaleProps($variant['options'] ?? [], $channelCategoryUuid);

            $skus[] = array_filter([
                'SellerSku' => $variant['sku'],
                'quantity' => 0,
                'price' => (float) ($variant['sell_price'] ?? 0),
                'package_weight' => (float) ($variant['weight'] ?? $product['weight'] ?? 0.1) ?: 0.1,
                'package_length' => (float) ($product['length'] ?? 0) ?: null,
                'package_width' => (float) ($product['width'] ?? 0) ?: null,
                'package_height' => (float) ($product['height'] ?? 0) ?: null,
                'SaleProp' => $saleProps ?: null,
            ], fn ($v) => $v !== null);
        }

        return [
            'Request' => [
                'Product' => array_filter([
                    'PrimaryCategory' => $this->resolveChannelCategoryId($product['category_id'] ?? null, $config),
                    'Images' => $imageUrls ? ['Image' => array_values($imageUrls)] : null,
                    'Attributes' => [
                        'name' => $product['name'] ?? 'Produk',
                        'description' => $product['description'] ?? ($product['name'] ?? ''),
                        'brand' => $config['brand'] ?? 'No Brand',
                    ],
                    'Skus' => ['Sku' => $skus],
                ], fn ($v) => $v !== null),
            ],
        ];
    }

    /** UUID kategori channel (untuk scoping atribut), beda dari external_id (untuk PrimaryCategory). */
    protected function resolveChannelCategoryUuid($categoryId): ?string
    {
        if (! $categoryId) {
            return null;
        }

        $channelId = DB::table('channels')->where('code', 'lazada')->value('id');

        return DB::table('category_channel_mappings')
            ->join('channel_categories', 'channel_categories.id', '=', 'category_channel_mappings.channel_category_id')
            ->where('category_channel_mappings.category_id', $categoryId)
            ->where('channel_categories.channel_id', $channelId)
            ->value('channel_categories.id');
    }

    /**
     * Bangun sale-property channel dari opsi varian internal:
     *  - key  = external_id atribut channel (nama sale-prop Lazada)
     *  - nilai tertutup → channel_attribute_options.external_id; free-text → apa adanya
     *  - atribut tak terpetakan → dilewati (atribut wajib ditangkap ChannelListingValidator)
     *
     * @return array<string, string>
     */
    protected function buildSaleProps(array $options, ?string $channelCategoryUuid): array
    {
        if (! $channelCategoryUuid || empty($options)) {
            return [];
        }

        $props = [];

        foreach ($options as $opt) {
            $attrId = $opt['attribute_id'] ?? null;
            $value = $opt['value'] ?? null;
            if (! $attrId || $value === null || $value === '') {
                continue;
            }

            $channelAttr = DB::table('attribute_channel_mappings as m')
                ->join('channel_attributes as c', 'c.id', '=', 'm.channel_attribute_id')
                ->where('m.attribute_id', $attrId)
                ->where('c.channel_category_id', $channelCategoryUuid)
                ->select('c.id', 'c.external_id')
                ->first();

            if (! $channelAttr) {
                continue;
            }

            $mappedValue = DB::table('channel_attribute_options')
                ->where('channel_attribute_id', $channelAttr->id)
                ->where(function ($q) use ($value) {
                    $needle = mb_strtolower((string) $value);
                    $q->whereRaw('LOWER(external_id) = ?', [$needle])
                        ->orWhereRaw('LOWER(name) = ?', [$needle]);
                })
                ->value('external_id');

            $props[$channelAttr->external_id] = $mappedValue !== null ? (string) $mappedValue : (string) $value;
        }

        return $props;
    }

    protected function resolveChannelCategoryId($categoryId, array $config): ?string
    {
        if ($categoryId) {
            $channelId = DB::table('channels')->where('code', 'lazada')->value('id');

            $externalId = DB::table('category_channel_mappings')
                ->join('channel_categories', 'channel_categories.id', '=', 'category_channel_mappings.channel_category_id')
                ->where('category_channel_mappings.category_id', $categoryId)
                ->where('channel_categories.channel_id', $channelId)
                ->value('channel_categories.external_id');

            if ($externalId) {
                return (string) $externalId;
            }
        }

        $fallback = $config['primary_category'] ?? null;

        return $fallback !== null ? (string) $fallback : null;
    }
}
