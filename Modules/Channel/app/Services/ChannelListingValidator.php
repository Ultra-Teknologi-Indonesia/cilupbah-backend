<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\DB;
use Modules\Product\Models\Product;

/**
 * Pre-flight validator: memastikan produk siap di-listing ke sebuah channel
 * SEBELUM dikirim ke marketplace. Mencegah penolakan di sisi marketplace
 * (filosofi no-500: gagal lokal dengan checklist yang jelas).
 *
 * Mengembalikan daftar issue terstruktur; kosong = lolos.
 *  - category_unmapped : kategori produk belum dipetakan ke channel
 *  - category_deprecated: kategori channel sudah tidak berlaku (perlu petakan ulang)
 *  - attribute_unmapped: atribut wajib channel belum dipetakan ke atribut internal
 *  - attribute_missing : atribut wajib belum punya nilai di produk
 *  - value_unmapped    : nilai produk belum punya padanan opsi channel (closed list)
 */
class ChannelListingValidator
{
    /**
     * @return array<int, array{code:string, attribute:?string, message:string}>
     */
    public function validate(Product $product, string $channelCode): array
    {
        $channelId = DB::table('channels')->where('code', $channelCode)->value('id');
        if (! $channelId) {
            return [$this->issue('category_unmapped', null, "Channel {$channelCode} belum tersedia.")];
        }

        // 1) Resolusi kategori internal → kategori channel (leaf).
        $channelCategory = DB::table('category_channel_mappings as m')
            ->join('channel_categories as c', 'c.id', '=', 'm.channel_category_id')
            ->where('m.category_id', $product->category_id)
            ->where('c.channel_id', $channelId)
            ->select('c.id', 'c.name', 'c.deprecated_at')
            ->first();

        if (! $channelCategory) {
            return [$this->issue(
                'category_unmapped',
                null,
                "Kategori produk belum dipetakan ke {$channelCode}."
            )];
        }

        // Kategori channel sudah tidak berlaku di marketplace → blokir, minta petakan ulang.
        if ($channelCategory->deprecated_at !== null) {
            return [$this->issue(
                'category_deprecated',
                $channelCategory->name,
                "Kategori {$channelCode} '{$channelCategory->name}' sudah tidak berlaku — petakan ulang."
            )];
        }

        // 2) Atribut wajib untuk kategori channel tersebut.
        $required = DB::table('channel_attributes')
            ->where('channel_category_id', $channelCategory->id)
            ->where('is_required', true)
            ->get(['id', 'external_id', 'name']);

        if ($required->isEmpty()) {
            return [];
        }

        $productValues = $this->collectProductValues($product->id); // [attribute_id => [values]]
        $issues = [];

        foreach ($required as $ca) {
            // 2a) Atribut channel → atribut internal.
            $internalAttrId = DB::table('attribute_channel_mappings')
                ->where('channel_attribute_id', $ca->id)
                ->value('attribute_id');

            if (! $internalAttrId) {
                $issues[] = $this->issue(
                    'attribute_unmapped',
                    $ca->name,
                    "Atribut wajib '{$ca->name}' belum dipetakan ke atribut internal."
                );
                continue;
            }

            // 2b) Produk punya nilai untuk atribut internal itu?
            $values = $productValues[$internalAttrId] ?? [];
            if (empty($values)) {
                $issues[] = $this->issue(
                    'attribute_missing',
                    $ca->name,
                    "Atribut wajib '{$ca->name}' belum diisi pada produk."
                );
                continue;
            }

            // 2c) Bila atribut bernilai tertutup (punya opsi), tiap nilai harus ada padanannya.
            $options = DB::table('channel_attribute_options')
                ->where('channel_attribute_id', $ca->id)
                ->get(['external_id', 'name']);

            if ($options->isEmpty()) {
                continue; // free-text: lolos
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

    /**
     * Kumpulkan nilai produk per atribut internal dari spesifikasi + opsi varian.
     *
     * @return array<int, array<int, string>>
     */
    private function collectProductValues(string $productId): array
    {
        $map = [];

        // Spesifikasi: text_value atau resolusi attribute_option_id → value.
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

        // Opsi varian.
        $opts = DB::table('variant_options as vo')
            ->join('product_variants as v', 'v.id', '=', 'vo.variant_id')
            ->where('v.product_id', $productId)
            ->get(['vo.attribute_id', 'vo.value']);

        foreach ($opts as $opt) {
            if ($opt->value !== null && $opt->value !== '') {
                $map[$opt->attribute_id][] = (string) $opt->value;
            }
        }

        // Distinct per atribut.
        foreach ($map as $attrId => $values) {
            $map[$attrId] = array_values(array_unique($values));
        }

        return $map;
    }

    /**
     * @return array{code:string, attribute:?string, message:string}
     */
    private function issue(string $code, ?string $attribute, string $message): array
    {
        return ['code' => $code, 'attribute' => $attribute, 'message' => $message];
    }
}
