<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\DB;
use Modules\Product\Models\Product;

class ChannelListingValidator
{

    public const SYSTEM_ATTRIBUTES = [
        'price', 'sellersku', 'quantity',
        'package_weight', 'package_height', 'package_width', 'package_length',
    ];

    public function validate(Product $product, string $channelCode): array
    {

        $issues = [];
        if ($this->lacksVariationAttributes($product)) {
            $issues[] = $this->issue(
                'variation_attribute_missing',
                null,
                "Produk multi-varian tanpa atribut variasi — wajib diisi sebelum upload ke {$channelCode}."
            );
        }

        $channelId = DB::table('channels')->where('code', $channelCode)->value('id');
        if (! $channelId) {
            return array_merge($issues, [$this->issue('category_unmapped', null, "Channel {$channelCode} belum tersedia.")]);
        }

        $channelCategory = DB::table('category_channel_mappings as m')
            ->join('channel_categories as c', 'c.id', '=', 'm.channel_category_id')
            ->where('m.category_id', $product->category_id)
            ->where('c.channel_id', $channelId)
            ->select('c.id', 'c.name', 'c.deprecated_at')
            ->first();

        if (! $channelCategory) {
            return array_merge($issues, [$this->issue(
                'category_unmapped',
                null,
                "Kategori produk belum dipetakan ke {$channelCode}."
            )]);
        }

        if ($channelCategory->deprecated_at !== null) {
            return array_merge($issues, [$this->issue(
                'category_deprecated',
                $channelCategory->name,
                "Kategori {$channelCode} '{$channelCategory->name}' sudah tidak berlaku — petakan ulang."
            )]);
        }

        $required = DB::table('channel_attributes')
            ->where('channel_category_id', $channelCategory->id)
            ->where('is_required', true)
            ->get(['id', 'external_id', 'name', 'is_sale_prop']);

        if ($required->isEmpty()) {
            return $issues;
        }

        $productValues = $this->collectProductValues($product->id);

        foreach ($required as $ca) {

            if (in_array(mb_strtolower((string) $ca->external_id), self::SYSTEM_ATTRIBUTES, true)) {
                continue;
            }

            $internalAttrId = DB::table('attribute_channel_mappings')
                ->where('channel_attribute_id', $ca->id)
                ->value('attribute_id');

            if (! $internalAttrId) {

                $hasOptions = ! $ca->is_sale_prop && DB::table('channel_attribute_options')
                    ->where('channel_attribute_id', $ca->id)
                    ->exists();

                if (! $hasOptions) {
                    $issues[] = $this->issue(
                        'attribute_unmapped',
                        $ca->name,
                        "Atribut wajib '{$ca->name}' belum dipetakan ke atribut internal."
                    );
                }
                continue;
            }

            $values = $productValues[$internalAttrId] ?? [];
            if (empty($values)) {
                $issues[] = $this->issue(
                    'attribute_missing',
                    $ca->name,
                    "Atribut wajib '{$ca->name}' belum diisi pada produk."
                );
                continue;
            }

            $options = DB::table('channel_attribute_options')
                ->where('channel_attribute_id', $ca->id)
                ->get(['external_id', 'name']);

            if ($options->isEmpty()) {
                continue; 
            }

            $allowed = $options
                ->flatMap(fn ($o) => [mb_strtolower($o->external_id), mb_strtolower($o->name)])
                ->unique();

            foreach ($values as $value) {
                if (! $allowed->contains(mb_strtolower((string) $value))) {
                    $issues[] = $this->issue(
                        'value_unmapped',
                        $ca->name,
                        "Nilai '{$value}' untuk '{$ca->name}' belum dipetakan ke opsi {$channelCode}."
                    );
                }
            }
        }

        return $issues;
    }

    public function lacksVariationAttributes(Product $product): bool
    {
        $variantCount = DB::table('product_variants')
            ->where('product_id', $product->id)
            ->count();

        if ($variantCount <= 1) {
            return false;
        }

        $hasAnyOptions = DB::table('variant_options as vo')
            ->join('product_variants as pv', 'pv.id', '=', 'vo.variant_id')
            ->where('pv.product_id', $product->id)
            ->exists();

        return ! $hasAnyOptions;
    }

    private function collectProductValues(string $productId): array
    {
        $map = [];

        $specs = DB::table('product_specifications as s')
            ->leftJoin('attribute_options as o', 'o.id', '=', 's.attribute_option_id')
            ->where('s.product_id', $productId)
            ->get(['s.attribute_id', 's.text_value', 'o.value as option_value']);

        foreach ($specs as $spec) {
            $value = $spec->text_value ?? $spec->option_value;
            if ($value !== null && $value !== '') {
                $map[$spec->attribute_id][] = (string) $value;
            }
        }

        $opts = DB::table('variant_options as vo')
            ->join('product_variants as v', 'v.id', '=', 'vo.variant_id')
            ->where('v.product_id', $productId)
            ->get(['vo.attribute_id', 'vo.value']);

        foreach ($opts as $opt) {
            if ($opt->value !== null && $opt->value !== '') {
                $map[$opt->attribute_id][] = (string) $opt->value;
            }
        }

        foreach ($map as $attrId => $values) {
            $map[$attrId] = array_values(array_unique($values));
        }

        return $map;
    }

    private function issue(string $code, ?string $attribute, string $message): array
    {
        return ['code' => $code, 'attribute' => $attribute, 'message' => $message];
    }
}
