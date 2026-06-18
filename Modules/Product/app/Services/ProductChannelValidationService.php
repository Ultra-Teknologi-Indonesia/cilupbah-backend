<?php

namespace Modules\Product\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\Channel;
use Modules\Channel\Services\ChannelListingValidator;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelValidation;

/**
 * Menghitung & memateralisasi status "Tidak Cocok" (atribut/harga/sku) per (produk × channel)
 * ke tabel product_channel_validations, agar lens pantauan cukup membaca kolom terindeks.
 *
 * Sumber kebenaran:
 *  - atribut : ChannelListingValidator::validate() (validasi skema kategori marketplace)
 *  - harga   : product_variant_channel_mappings.synced_price vs coalesce(override_price, sell_price)
 *  - sku     : product_variant_channel_mappings.channel_seller_sku vs product_variants.sku
 */
class ProductChannelValidationService
{
    /** Channel yang sudah terintegrasi (Shopee ditunda). */
    public const IN_SCOPE_CHANNELS = ['tiktok', 'lazada'];

    /** Issue code yang dihitung sebagai "Atribut Tidak Cocok" (kategori dikecualikan). */
    private const ATTRIBUTE_ISSUE_CODES = [
        'variation_attribute_missing',
        'attribute_unmapped',
        'attribute_missing',
        'value_unmapped',
    ];

    public function __construct(private ChannelListingValidator $validator) {}

    public function recompute(Product $product): void
    {
        $product->loadMissing([
            'variants:id,product_id,sku,sell_price',
            'channelMappings.channelShop:id,channel_id',
            'channelMappings.variantMappings',
        ]);

        // Kelompokkan mapping per channel (lewat toko), hanya channel in-scope & aktif.
        $mappingsByChannel = $product->channelMappings
            ->filter(fn ($m) => $m->channelShop !== null)
            ->groupBy(fn ($m) => $m->channelShop->channel_id);

        $channels = Channel::query()
            ->whereIn('id', $mappingsByChannel->keys()->all() ?: ['-'])
            ->where('is_active', true)
            ->whereIn('code', self::IN_SCOPE_CHANNELS)
            ->get();

        DB::transaction(function () use ($product, $channels, $mappingsByChannel) {
            $keep = [];

            foreach ($channels as $channel) {
                $mappings = $mappingsByChannel->get($channel->id, collect());

                [$attrStatus, $attrIssues] = $this->attributeStatus($product, $channel);
                [$priceStatus, $priceIssues] = $this->priceStatus($product, $mappings);
                [$skuStatus, $skuIssues] = $this->skuStatus($product, $mappings);

                ProductChannelValidation::updateOrCreate(
                    ['product_id' => $product->id, 'channel_id' => $channel->id],
                    [
                        'attribute_status' => $attrStatus,
                        'attribute_issues' => $attrIssues ?: null,
                        'price_status' => $priceStatus,
                        'price_issues' => $priceIssues ?: null,
                        'sku_status' => $skuStatus,
                        'sku_issues' => $skuIssues ?: null,
                        'checked_at' => now(),
                    ]
                );

                $keep[] = $channel->id;
            }

            // Bersihkan baris untuk channel yang sudah tidak relevan lagi.
            ProductChannelValidation::query()
                ->where('product_id', $product->id)
                ->whereNotIn('channel_id', $keep ?: ['-'])
                ->delete();
        });
    }

    /**
     * @return array{0:string,1:array}
     */
    private function attributeStatus(Product $product, Channel $channel): array
    {
        $issues = $this->validator->validate($product, $channel->code);

        $relevant = array_values(array_filter(
            $issues,
            fn ($i) => in_array($i['code'], self::ATTRIBUTE_ISSUE_CODES, true)
        ));

        if (! empty($relevant)) {
            return [ProductChannelValidation::STATUS_MISMATCH, $relevant];
        }

        // Tanpa issue: hanya bisa dipastikan "ok" bila skema atribut channel sudah di-ingest.
        // Bila belum (mis. TikTok belum sync skema), tandai "na" agar tidak false-negative.
        return [
            $this->channelSchemaIngested($channel->id)
                ? ProductChannelValidation::STATUS_OK
                : ProductChannelValidation::STATUS_NA,
            [],
        ];
    }

    /**
     * @param  Collection  $mappings  product_channel_mappings untuk channel ini
     * @return array{0:string,1:array}
     */
    private function priceStatus(Product $product, Collection $mappings): array
    {
        $issues = [];
        $hasData = false;

        foreach ($mappings as $mapping) {
            foreach ($mapping->variantMappings as $vm) {
                if ($vm->synced_price === null) {
                    continue;
                }
                $hasData = true;

                $variant = $product->variants->firstWhere('id', $vm->variant_id);
                if (! $variant) {
                    continue;
                }

                $effective = $vm->override_price ?? $variant->sell_price;
                if ($this->numbersDiffer($vm->synced_price, $effective)) {
                    $issues[] = [
                        'variant_id' => $vm->variant_id,
                        'sku' => $variant->sku,
                        'master_price' => (float) $effective,
                        'channel_price' => (float) $vm->synced_price,
                    ];
                }
            }
        }

        return $this->resolveStatus($hasData, $issues);
    }

    /**
     * @return array{0:string,1:array}
     */
    private function skuStatus(Product $product, Collection $mappings): array
    {
        $issues = [];
        $hasData = false;

        foreach ($mappings as $mapping) {
            foreach ($mapping->variantMappings as $vm) {
                if ($vm->channel_seller_sku === null || $vm->channel_seller_sku === '') {
                    continue;
                }
                $hasData = true;

                $variant = $product->variants->firstWhere('id', $vm->variant_id);
                if (! $variant) {
                    continue;
                }

                if ((string) $vm->channel_seller_sku !== (string) $variant->sku) {
                    $issues[] = [
                        'variant_id' => $vm->variant_id,
                        'master_sku' => $variant->sku,
                        'channel_sku' => $vm->channel_seller_sku,
                    ];
                }
            }
        }

        return $this->resolveStatus($hasData, $issues);
    }

    /**
     * @return array{0:string,1:array}
     */
    private function resolveStatus(bool $hasData, array $issues): array
    {
        if (! empty($issues)) {
            return [ProductChannelValidation::STATUS_MISMATCH, $issues];
        }

        // Tidak ada data sinkron sama sekali → belum bisa dinilai.
        return [
            $hasData ? ProductChannelValidation::STATUS_OK : ProductChannelValidation::STATUS_NA,
            [],
        ];
    }

    private function numbersDiffer($a, $b): bool
    {
        return abs((float) $a - (float) $b) > 0.005;
    }

    private function channelSchemaIngested(string $channelId): bool
    {
        return DB::table('channel_attributes as ca')
            ->join('channel_categories as cc', 'cc.id', '=', 'ca.channel_category_id')
            ->where('cc.channel_id', $channelId)
            ->exists();
    }
}
