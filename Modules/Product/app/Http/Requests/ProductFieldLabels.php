<?php

namespace Modules\Product\Http\Requests;

/**
 * Label field & pesan validasi Bahasa Indonesia untuk form produk.
 * Dipakai CreateProductRequest & UpdateProductRequest agar pesan validasi
 * user-facing (bukan "The variants.0.sku field is required").
 */
class ProductFieldLabels
{
    public static function attributes(): array
    {
        return [
            'name'               => 'Nama produk',
            'sku'                => 'SKU produk',
            'category_id'        => 'Kategori',
            'brand'              => 'Merek',
            'description'        => 'Deskripsi',
            'weight'             => 'Berat',
            'package_length'     => 'Panjang paket',
            'package_width'      => 'Lebar paket',
            'package_height'     => 'Tinggi paket',
            'indent_days'        => 'Lama indent',
            'sales_account_id'   => 'Akun Penjualan',
            'sales_return_account_id' => 'Akun Retur Penjualan',
            'inventory_account_id' => 'Akun Persediaan',
            'cogs_account_id'    => 'Akun HPP',

            'media'              => 'Foto/Video produk',
            'media.*'            => 'Foto/Video',
            'media.*.url'        => 'Foto/Video',
            'media.*.media_uuid' => 'Foto/Video',

            'variants'                   => 'Varian',
            'variants.*'                 => 'Varian',
            'variants.*.sku'             => 'SKU varian',
            'variants.*.barcode'         => 'Barcode varian',
            'variants.*.sell_price'      => 'Harga jual varian',
            'variants.*.weight'          => 'Berat varian',
            'variants.*.options'         => 'Opsi varian',
            'variants.*.options.*.value' => 'Nilai opsi varian',
            'variants.*.options.*.attribute_id' => 'Jenis opsi varian',
            'variants.*.wholesale_prices' => 'Harga grosir varian',

            'variation_types'                 => 'Jenis varian',
            'variation_types.*.attribute_id'  => 'Jenis varian',
            'variation_types.*.name'          => 'Nama jenis varian',

            'specifications'                  => 'Spesifikasi',
            'specifications.*.attribute_id'   => 'Spesifikasi',
            'specifications.*.value'          => 'Nilai spesifikasi',
        ];
    }

    /**
     * Pesan level-rule (berlaku untuk semua field) dalam Bahasa Indonesia.
     */
    public static function ruleMessages(): array
    {
        return [
            'required'         => ':attribute wajib diisi.',
            'required_if'      => ':attribute wajib diisi.',
            'required_with'    => ':attribute wajib diisi.',
            'required_without' => ':attribute wajib diisi.',
            'string'           => ':attribute harus berupa teks.',
            'numeric'          => ':attribute harus berupa angka.',
            'integer'          => ':attribute harus berupa angka bulat.',
            'boolean'          => ':attribute harus bernilai ya atau tidak.',
            'array'            => ':attribute harus berupa daftar.',
            'min'              => ':attribute minimal :min.',
            'max'              => ':attribute maksimal :max.',
            'uuid'             => ':attribute tidak valid.',
            'url'              => ':attribute harus berupa URL yang valid.',
            'exists'           => ':attribute yang dipilih tidak valid atau tidak tersedia.',
            'unique'           => ':attribute sudah digunakan.',
            'distinct'         => ':attribute tidak boleh duplikat.',
            'image'            => ':attribute harus berupa gambar.',
            'mimes'            => ':attribute harus berformat: :values.',
        ];
    }
}
