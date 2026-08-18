<?php

namespace Modules\Product\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ProductPickerHydrator
{

    public static function hydrate(
        LengthAwarePaginatorContract|LengthAwarePaginator $paginator,
        ?array $matchingVariantIds = null,
        ?string $search = null
    ): LengthAwarePaginatorContract|LengthAwarePaginator {
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

        $rawVariationTypes = DB::table('product_variation_types')
            ->whereIn('product_id', $allProductIds)
            ->orderBy('sort_order')
            ->get(['id', 'product_id', 'attribute_id', 'sort_order']);

        $variationTypesByProduct = [];
        foreach ($rawVariationTypes as $vt) {
            $variationTypesByProduct[$vt->product_id][] = $vt;
        }

        $rawVariants = DB::table('product_variants')
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

        $rawBundleItems = DB::table('product_bundle_items')
            ->join('product_variants', 'product_variants.id', '=', 'product_bundle_items.component_variant_id')
            ->whereIn('product_bundle_items.bundle_product_id', $allProductIds)
            ->whereNull('product_variants.deleted_at')
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
        foreach ($rawBundleItems as $bi) {
            $bundleItemsByProduct[$bi->bundle_product_id][] = $bi;
            $variantIds[] = $bi->component_variant_id;
        }

        $variantIds = array_values(array_unique($variantIds));

        $rawOptions = ! empty($variantIds)
            ? DB::table('variant_options')
                ->whereIn('variant_id', $variantIds)
                ->get(['id', 'variant_id', 'attribute_id', 'value'])
            : collect();

        $optionsByVariant = [];
        foreach ($rawOptions as $opt) {
            $optionsByVariant[$opt->variant_id][] = $opt;
        }

        $rawMedia = DB::table('product_media')
            ->where(function ($q) use ($allProductIds, $variantIds) {
                $q->whereIn('product_id', $allProductIds);
                if (! empty($variantIds)) {
                    $q->orWhereIn('variant_id', $variantIds);
                }
            })
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->get(['id', 'product_id', 'variant_id', 'url', 'sort_order', 'is_primary']);

        $productThumbnails = [];
        $variantThumbnails = [];
        foreach ($rawMedia as $m) {
            if ($m->variant_id) {
                if (! isset($variantThumbnails[$m->variant_id])) {
                    $variantThumbnails[$m->variant_id] = $m->url;
                }
            } else {
                if ($m->product_id && ! isset($productThumbnails[$m->product_id])) {
                    $productThumbnails[$m->product_id] = $m->url;
                }
            }
        }

        $rawVariantChannelMappings = ! empty($variantIds)
            ? DB::table('product_variant_channel_mappings')
                ->whereIn('variant_id', $variantIds)
                ->get(['id', 'variant_id', 'product_channel_mapping_id'])
            : collect();

        $variantStoreNames = [];
        if ($rawVariantChannelMappings->isNotEmpty()) {
            $mappingIds = $rawVariantChannelMappings->pluck('product_channel_mapping_id')->unique()->all();
            $pcmShops = DB::table('product_channel_mappings')
                ->whereIn('id', $mappingIds)
                ->pluck('channel_shop_id', 'id')
                ->all();

            foreach ($rawVariantChannelMappings as $vcm) {
                $shopId = $pcmShops[$vcm->product_channel_mapping_id] ?? null;
                $shopName = $shopId && isset($shops[$shopId]) ? $shops[$shopId]->shop_name : null;
                if ($shopName) {
                    $variantStoreNames[$vcm->variant_id][] = ['store_name' => $shopName];
                }
            }
        }

        $matchingVariantSet = $matchingVariantIds !== null ? array_flip($matchingVariantIds) : null;

        $transformed = [];
        foreach ($collection as $product) {
            $memberIds = $product->merge_member_ids ?? [$product->id];
            $isMerged = (bool) ($product->is_merged ?? false);
            $masterName = $isMerged ? ($product->merge_master_name ?? $product->name) : $product->name;
            $isBundle = (bool) $product->is_bundle;

            $allVariants = [];
            $allVariationTypes = [];

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
            }

            $variantItems = [];
            $minSellPrice = null;
            $valuesByAttribute = [];

            $bItems = $isBundle ? ($bundleItemsByProduct[$product->id] ?? []) : [];
            $componentsCount = count($bItems);

            if ($isBundle && ! empty($bItems)) {
                foreach ($bItems as $bi) {
                    if ($matchingVariantSet !== null && ! isset($matchingVariantSet[$bi->component_variant_id])) {
                        continue;
                    }

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
                    if ($matchingVariantSet !== null && ! isset($matchingVariantSet[$v->id])) {
                        continue;
                    }

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

            if ($matchingVariantSet !== null && empty($variantItems)) {
                continue;
            }

            $productThumbnail = $productThumbnails[$product->id] ?? null;
            if (! $productThumbnail && $isMerged) {
                foreach ($memberIds as $mid) {
                    if (! empty($productThumbnails[$mid])) {
                        $productThumbnail = $productThumbnails[$mid];
                        break;
                    }
                }
            }
            if (! $productThumbnail && ! empty($variantItems)) {
                foreach ($variantItems as $vi) {
                    if (! empty($vi['thumbnail'])) {
                        $productThumbnail = $vi['thumbnail'];
                        break;
                    }
                }
            }

            $variations = [];
            foreach ($allVariationTypes as $vt) {
                $attrName = $attributes[$vt->attribute_id] ?? null;
                $vals = array_keys($valuesByAttribute[$vt->attribute_id] ?? []);
                $variations[] = [
                    'label' => $attrName,
                    'values' => $vals,
                ];
            }

            $transformed[] = [
                'item_group_id' => $product->id,
                'status' => $product->status,
                'is_po' => $product->order_type === 'PREORDER',
                'is_bundle' => $isBundle,
                'sku' => $product->sku ?? (count($variantItems) === 1 ? ($variantItems[0]['item_code'] ?? null) : null),
                'item_name' => $masterName,
                'last_modified' => $product->updated_at,
                'variations' => $variations,
                'sell_price' => $minSellPrice,
                'item_category_id' => $product->category_id,
                'category_name' => $product->category_id ? ($categoryNames[$product->category_id] ?? null) : null,
                'is_consignment' => (bool) $product->is_consignment,
                'variants' => $variantItems,
                'total_components' => $componentsCount,
                'total_variants' => count($variantItems),
                'thumbnail' => $productThumbnail,
                'is_merged' => $isMerged,
                'master_name' => $isMerged ? $masterName : null,
                'member_ids' => $memberIds,
            ];
        }

        return new LengthAwarePaginator(
            $transformed,
            $paginator->total(),
            $paginator->perPage(),
            $paginator->currentPage(),
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }
}
