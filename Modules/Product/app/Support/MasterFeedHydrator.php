<?php

namespace Modules\Product\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class MasterFeedHydrator
{
    public static function hydrate(LengthAwarePaginatorContract|LengthAwarePaginator $paginator): LengthAwarePaginatorContract|LengthAwarePaginator
    {
        $items = $paginator->items();
        if (empty($items)) {
            return $paginator;
        }

        $collection = collect($items);

        $allProductIds = [];
        $categoryIds = [];

        foreach ($collection as $product) {
            $allProductIds[] = $product->id;
            if ($product->category_id) {
                $categoryIds[$product->category_id] = true;
            }
            if (! empty($product->merge_member_ids)) {
                foreach ($product->merge_member_ids as $mid) {
                    $allProductIds[] = $mid;
                }
            }
        }
        $allProductIds = array_values(array_unique($allProductIds));

        $categoryNames = ! empty($categoryIds)
            ? DB::table('categories')->whereIn('id', array_keys($categoryIds))->pluck('name', 'id')->all()
            : [];

        $attributes = DB::table('attributes')->pluck('name', 'id')->all();
        $shops = DB::table('channel_shops')
            ->select(['id', 'channel_id', 'shop_id', 'shop_name'])
            ->get()
            ->keyBy('id')
            ->all();
        $channels = DB::table('channels')
            ->select(['id', 'code', 'name'])
            ->get()
            ->keyBy('id')
            ->all();

        $rawVariationTypes = DB::table('product_variation_types')
            ->whereIn('product_id', $allProductIds)
            ->orderBy('sort_order')
            ->get(['id', 'product_id', 'attribute_id', 'sort_order']);

        $variationTypesByProduct = [];
        foreach ($rawVariationTypes as $vt) {
            $variationTypesByProduct[$vt->product_id][] = $vt;
        }

        $rawVariants = TechnicalSku::exclude(DB::table('product_variants'))
            ->whereIn('product_id', $allProductIds)
            ->whereNull('deleted_at')
            ->orderBy('sequence_item')
            ->orderBy('id')
            ->get([
                'id', 'product_id', 'sku', 'barcode', 'sell_price', 'tax_rate',
                'is_internal', 'sequence_item',
            ]);

        $variantIds = [];
        $variantsByProduct = [];
        foreach ($rawVariants as $v) {
            $variantIds[] = $v->id;
            $variantsByProduct[$v->product_id][] = $v;
        }

        $rawBundleItems = TechnicalSku::exclude(DB::table('product_bundle_items')
            ->join('product_variants', 'product_variants.id', '=', 'product_bundle_items.component_variant_id')
            ->whereIn('product_bundle_items.bundle_product_id', $allProductIds)
            ->whereNull('product_variants.deleted_at'), 'product_variants.sku')
            ->get([
                'product_bundle_items.bundle_product_id',
                'product_bundle_items.component_variant_id',
                'product_bundle_items.qty',
                'product_variants.id',
                'product_variants.product_id as component_product_id',
                'product_variants.sku',
                'product_variants.barcode',
                'product_variants.sell_price',
                'product_variants.tax_rate',
                'product_variants.is_internal',
                'product_variants.sequence_item',
            ]);

        $bundleItemsByProduct = [];
        $bundleComponentVariantIds = [];
        foreach ($rawBundleItems as $bi) {
            $bundleItemsByProduct[$bi->bundle_product_id][] = $bi;
            $bundleComponentVariantIds[] = $bi->component_variant_id;
        }

        $allLookupVariantIds = array_values(array_unique(array_merge($variantIds, $bundleComponentVariantIds)));

        $optionsByVariant = [];
        if (! empty($allLookupVariantIds)) {
            $rawOptions = DB::table('variant_options')
                ->whereIn('variant_id', $allLookupVariantIds)
                ->get(['id', 'variant_id', 'attribute_id', 'value']);

            foreach ($rawOptions as $opt) {
                $optionsByVariant[$opt->variant_id][] = $opt;
            }
        }

        $productLevelMedia = [];
        $primaryThumbnailByProduct = [];
        $variantThumbnails = [];

        $rawMedia = DB::table('product_media')
            ->whereIn('product_id', $allProductIds)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'product_id', 'variant_id', 'url', 'is_primary', 'sort_order']);

        foreach ($rawMedia as $m) {
            if ($m->variant_id) {
                if ($m->is_primary || ! isset($variantThumbnails[$m->variant_id])) {
                    $variantThumbnails[$m->variant_id] = $m->url;
                }
            } else {
                $productLevelMedia[$m->product_id][] = $m;
                if ($m->is_primary && ! isset($primaryThumbnailByProduct[$m->product_id])) {
                    $primaryThumbnailByProduct[$m->product_id] = $m->url;
                }
            }
        }
        foreach ($allProductIds as $pid) {
            if (! isset($primaryThumbnailByProduct[$pid]) && ! empty($productLevelMedia[$pid])) {
                $primaryThumbnailByProduct[$pid] = $productLevelMedia[$pid][0]->url;
            }
        }

        $rawChannelMappings = DB::table('product_channel_mappings')
            ->whereIn('product_id', $allProductIds)
            ->get([
                'id', 'product_id', 'channel_shop_id', 'external_product_id',
                'channel_url', 'sync_status', 'error_message',
            ]);

        $channelMappingsByProduct = [];
        $mappingIdToShopName = [];

        foreach ($rawChannelMappings as $cm) {
            $channelMappingsByProduct[$cm->product_id][] = $cm;
            $shop = $shops[$cm->channel_shop_id] ?? null;
            if ($shop) {
                $mappingIdToShopName[$cm->id] = $shop->shop_name;
            }
        }

        $variantStoreNames = [];
        if (! empty($allLookupVariantIds)) {
            $rawVariantChannelMappings = DB::table('product_variant_channel_mappings')
                ->whereIn('variant_id', $allLookupVariantIds)
                ->get(['id', 'variant_id', 'product_channel_mapping_id']);

            foreach ($rawVariantChannelMappings as $vcm) {
                $shopName = $mappingIdToShopName[$vcm->product_channel_mapping_id] ?? null;
                if ($shopName !== null) {
                    $variantStoreNames[$vcm->variant_id][] = ['store_name' => $shopName];
                }
            }
        }

        $transformed = [];
        foreach ($collection as $product) {
            $memberIds = $product->merge_member_ids ?? [$product->id];
            $isMerged = (bool) ($product->is_merged ?? false);
            $masterName = $isMerged ? ($product->merge_master_name ?? $product->name) : $product->name;
            $isBundle = (bool) $product->is_bundle;

            $allVariants = [];
            $allVariationTypes = [];
            $allChannelMappings = [];

            foreach ($memberIds as $mid) {
                if (! empty($variantsByProduct[$mid])) {
                    foreach ($variantsByProduct[$mid] as $v) {
                        $allVariants[] = $v;
                    }
                }
                if (! empty($variationTypesByProduct[$mid])) {
                    foreach ($variationTypesByProduct[$mid] as $vt) {
                        $allVariationTypes[$vt->id] = $vt;
                    }
                }
                if (! empty($channelMappingsByProduct[$mid])) {
                    foreach ($channelMappingsByProduct[$mid] as $cm) {
                        $allChannelMappings[] = $cm;
                    }
                }
            }

            $variantItems = [];
            $minSellPrice = null;
            $valuesByAttribute = [];

            $bItems = $isBundle ? ($bundleItemsByProduct[$product->id] ?? []) : [];
            $componentsCount = count($bItems);

            if ($isBundle && ! empty($bItems)) {
                foreach ($bItems as $bi) {
                    $opts = $optionsByVariant[$bi->component_variant_id] ?? [];
                    $varVariationValues = [];

                    foreach ($opts as $opt) {
                        $attrName = $attributes[$opt->attribute_id] ?? null;
                        $varVariationValues[] = [
                            'label' => $attrName,
                            'value' => $opt->value,
                        ];
                    }

                    if ($bi->sell_price !== null) {
                        $priceVal = (float) $bi->sell_price;
                        $minSellPrice = $minSellPrice === null ? $priceVal : min($minSellPrice, $priceVal);
                    }

                    $variantThumbnail = $variantThumbnails[$bi->component_variant_id] ?? null;

                    $variantItems[] = [
                        'item_group_id' => $product->id,
                        'item_id' => $bi->component_variant_id,
                        'item_code' => $bi->sku,
                        'item_name' => $masterName,
                        'is_bundle' => true,
                        'is_consignment' => (bool) $product->is_consignment,
                        'variation_values' => $varVariationValues,
                        'is_internal' => (bool) $bi->is_internal,
                        'barcode' => $bi->barcode,
                        'tax_rate' => $bi->tax_rate !== null ? (float) $bi->tax_rate : null,
                        'thumbnail' => $variantThumbnail,
                        'store_names' => $variantStoreNames[$bi->component_variant_id] ?? [],
                        'sell_price' => $bi->sell_price !== null ? (float) $bi->sell_price : null,
                        'sequence_item' => $bi->sequence_item !== null ? (int) $bi->sequence_item : null,
                        'qty' => (int) $bi->qty,
                    ];
                }
            } else {
                foreach ($allVariants as $v) {
                    $opts = $optionsByVariant[$v->id] ?? [];
                    $varVariationValues = [];

                    foreach ($opts as $opt) {
                        $attrName = $attributes[$opt->attribute_id] ?? null;
                        $varVariationValues[] = [
                            'label' => $attrName,
                            'value' => $opt->value,
                        ];
                        $valuesByAttribute[$opt->attribute_id][$opt->value] = true;
                    }

                    if ($v->sell_price !== null) {
                        $priceVal = (float) $v->sell_price;
                        $minSellPrice = $minSellPrice === null ? $priceVal : min($minSellPrice, $priceVal);
                    }

                    $variantThumbnail = $variantThumbnails[$v->id] ?? null;

                    $variantItems[] = [
                        'item_group_id' => $product->id,
                        'item_id' => $v->id,
                        'item_code' => $v->sku,
                        'item_name' => $masterName,
                        'is_bundle' => false,
                        'is_consignment' => (bool) $product->is_consignment,
                        'variation_values' => $varVariationValues,
                        'is_internal' => (bool) $v->is_internal,
                        'barcode' => $v->barcode,
                        'tax_rate' => $v->tax_rate !== null ? (float) $v->tax_rate : null,
                        'thumbnail' => $variantThumbnail,
                        'store_names' => $variantStoreNames[$v->id] ?? [],
                        'sell_price' => $v->sell_price !== null ? (float) $v->sell_price : null,
                        'sequence_item' => $v->sequence_item !== null ? (int) $v->sequence_item : null,
                    ];
                }
            }

            $masterVariations = [];
            usort($allVariationTypes, fn ($a, $b) => $a->sort_order <=> $b->sort_order);
            foreach ($allVariationTypes as $vt) {
                $attrName = $attributes[$vt->attribute_id] ?? null;
                $masterVariations[] = [
                    'label' => $attrName,
                    'values' => array_keys($valuesByAttribute[$vt->attribute_id] ?? []),
                ];
            }

            $onlineStatusList = [];
            foreach ($allChannelMappings as $cm) {
                if (! $cm->external_product_id && in_array($cm->sync_status, ['failed', 'pending'])) {
                    continue;
                }

                $shop = $shops[$cm->channel_shop_id] ?? null;
                $channel = $shop ? ($channels[$shop->channel_id] ?? null) : null;

                $url = $cm->channel_url ?: ChannelUrlBuilder::build(
                    $channel?->code,
                    $cm->external_product_id,
                    $shop?->shop_id,
                );

                $onlineStatusList[] = [
                    'channel_id' => $shop?->channel_id,
                    'channel_code' => $channel?->code,
                    'channel_name' => $channel?->name,
                    'store_id' => $cm->channel_shop_id,
                    'store_name' => $shop?->shop_name,
                    'shop_id' => $shop?->shop_id,
                    'channel_group_id' => $cm->external_product_id,
                    'channel_url' => $url,
                    'error_text' => $cm->error_message,
                ];
            }

            $thumbnail = $isBundle ? null : ($primaryThumbnailByProduct[$product->id] ?? null);
            if (! $isBundle) {
                if (! $thumbnail && $isMerged) {
                    foreach ($memberIds as $mid) {
                        if (isset($primaryThumbnailByProduct[$mid])) {
                            $thumbnail = $primaryThumbnailByProduct[$mid];
                            break;
                        }
                    }
                }
                if (! $thumbnail && ! empty($variantItems)) {
                    foreach ($variantItems as $vi) {
                        if (! empty($vi['thumbnail'])) {
                            $thumbnail = $vi['thumbnail'];
                            break;
                        }
                    }
                }
            }

            $transformed[] = [
                'item_group_id' => $product->id,
                'status' => $product->status,
                'is_po' => $product->order_type === 'PREORDER',
                'is_bundle' => $isBundle,
                'sku' => $product->sku ?? (! empty($variantItems) && count($variantItems) === 1 ? ($variantItems[0]['item_code'] ?? null) : null),
                'item_name' => $masterName,
                'last_modified' => $product->updated_at,
                'variations' => $masterVariations,
                'sell_price' => $minSellPrice,
                'item_category_id' => $product->category_id,
                'category_name' => $categoryNames[$product->category_id] ?? null,
                'is_consignment' => (bool) $product->is_consignment,
                'variants' => $variantItems,
                'total_components' => $componentsCount,
                'total_variants' => $isBundle ? $componentsCount : count($variantItems),
                'online_status' => $onlineStatusList,
                'thumbnail' => $thumbnail,
                'is_merged' => $isMerged,
                'master_name' => $isMerged ? $masterName : null,
                'member_ids' => array_values($memberIds),
            ];
        }

        if (method_exists($paginator, 'setCollection')) {
            $paginator->setCollection(collect($transformed));
        }

        return $paginator;
    }
}
