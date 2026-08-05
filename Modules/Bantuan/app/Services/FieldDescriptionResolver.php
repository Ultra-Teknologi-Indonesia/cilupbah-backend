<?php

namespace Modules\Bantuan\Services;

use Illuminate\Support\Str;

class FieldDescriptionResolver
{
    private const DICTIONARY = [

        'id'                => 'Primary key resource ini (UUID string).',
        'sku'               => 'Stock Keeping Unit — kode unik varian produk.',
        'no_ref'            => 'Nomor referensi eksternal (opsional).',
        'transaction_date'  => 'Tanggal transaksi (format YYYY-MM-DD).',
        'created_at'        => 'Timestamp pembuatan record (ISO 8601).',
        'updated_at'        => 'Timestamp perubahan terakhir (ISO 8601).',
        'deleted_at'        => 'Timestamp soft-delete (null bila aktif).',

        'customer_id'       => 'ID pelanggan (kontak) — referensi ke tabel contacts.',
        'contact_id'        => 'ID kontak (pelanggan atau pemasok) — referensi ke tabel contacts.',
        'supplier_id'       => 'ID pemasok — referensi ke tabel contacts.',
        'location_id'       => 'ID lokasi/gudang — referensi ke tabel locations.',
        'bin_id'            => 'ID rak (bin) — referensi ke tabel location_bins.',
        'zone_id'           => 'ID zona lokasi — referensi ke tabel location_zones.',
        'internal_store_id' => 'ID toko internal (POS/offline).',
        'salesman_id'       => 'ID sales/petugas penjualan.',
        'user_id'           => 'ID pengguna.',
        'assigned_to'       => 'ID pengguna yang di-assign untuk mengerjakan.',
        'product_id'        => 'ID produk (master, bukan varian).',
        'variant_id'        => 'ID varian produk.',
        'channel_id'        => 'ID channel marketplace.',
        'shop_id'           => 'ID toko marketplace.',
        'category_id'       => 'ID kategori produk.',
        'brand_id'          => 'ID merek produk.',
        'tax_id'            => 'ID pajak (PPN/PPh).',
        'courier_id'        => 'ID kurir (master).',

        'customer_name'     => 'Nama pelanggan (bebas teks).',
        'shipping_full_name'=> 'Nama lengkap penerima paket.',
        'shipping_phone'    => 'Telepon penerima. Standar E.164 (mis. +6281234567890) — dikecualikan untuk webhook.',
        'shipping_address'  => 'Alamat lengkap pengiriman.',
        'shipping_area'     => 'Kecamatan/kelurahan.',
        'shipping_city'     => 'Kota/kabupaten.',
        'shipping_province' => 'Provinsi.',
        'shipping_post_code'=> 'Kode pos.',
        'shipping_country'  => 'Negara (default Indonesia).',
        'shipping_coordinate' => 'Koordinat lat,lng untuk pin lokasi pengiriman.',

        'sub_total'         => 'Subtotal item sebelum diskon & ongkir.',
        'total_disc'        => 'Total diskon di level pesanan.',
        'other_discount'    => 'Diskon lain (voucher, promo tambahan).',
        'total_tax'         => 'Total pajak (PPN).',
        'shipping_cost'     => 'Ongkos kirim yang ditagihkan.',
        'shipping_discount' => 'Diskon ongkir.',
        'insurance_cost'    => 'Biaya asuransi pengiriman.',
        'service_fee'       => 'Biaya layanan marketplace.',
        'seller_voucher'    => 'Voucher penjual.',
        'order_processing_fee' => 'Biaya proses pesanan (channel).',
        'grand_total'       => 'Total akhir yang dibayarkan pelanggan.',
        'price_includes_tax'=> 'True bila harga item sudah termasuk pajak.',
        'price'             => 'Harga satuan item.',
        'amount'            => 'Nominal (jumlah uang).',
        'disc'              => 'Nilai diskon per item.',
        'disc_percent'      => 'Persentase diskon (0–100).',
        'tax_amount'        => 'Nilai pajak per item.',
        'balance'           => 'Saldo tersisa.',

        'qty'               => 'Kuantitas (satuan default).',
        'qty_in_base'       => 'Kuantitas dalam satuan dasar (base unit).',
        'quantity'          => 'Kuantitas.',
        'stock'             => 'Jumlah stok fisik.',
        'on_hand'           => 'Stok fisik yang ditempatkan di rak.',
        'available'         => 'Stok yang bisa dialokasi (on_hand - on_order).',
        'sellable'          => 'Stok yang boleh dipush ke channel.',
        'pickable'          => 'Stok yang boleh dipilih di picking.',
        'on_order'          => 'Stok yang sudah dialokasi ke pesanan aktif.',
        'transit'           => 'Stok yang sedang dalam perjalanan antar gudang.',

        'status'            => 'Status resource. Nilai valid tergantung modul — lihat enum di kolom rules.',
        'is_paid'           => 'True bila sudah dibayar.',
        'is_cod'            => 'True bila metode COD (bayar di tempat).',
        'is_active'         => 'True bila aktif; false = archived/deactivated.',
        'is_canceled'       => 'True bila sudah dibatalkan.',

        'delivery_method'   => 'Metode pengiriman: COURIER (kurir), SELF_PICKUP (ambil sendiri), atau INSTANT (kurir instan).',
        'shipping_provider' => 'Nama kurir (mis. JNE, JNT, SiCepat). Wajib bila delivery_method = COURIER.',
        'tracking_number'   => 'Nomor resi/tracking dari kurir.',
        'order_weight_gram' => 'Berat total pesanan (gram).',

        'salesorder_no'     => 'Nomor pesanan penjualan (unik).',
        'purchase_no'       => 'Nomor pesanan pembelian (PO).',
        'transfer_no'       => 'Nomor dokumen transfer.',
        'note'              => 'Catatan bebas.',
        'notes'             => 'Catatan bebas.',
        'reason'            => 'Alasan (untuk batal, retur, penyesuaian).',
        'description'       => 'Deskripsi teks bebas.',
        'name'              => 'Nama resource.',
        'code'              => 'Kode unik resource.',
        'email'             => 'Alamat email (RFC 5321).',
        'phone'             => 'Nomor telepon (E.164).',
        'password'          => 'Password login (min 8 karakter).',

        'items'             => 'Daftar item dalam dokumen (minimal 1 baris).',
        'item_id'           => 'ID/kode item dari sumber (channel atau internal).',
    ];

    public function describe(string $field, string $type = 'string'): string
    {

        $key = $field;
        if (str_contains($key, '.')) {
            $parts = explode('.', $key);
            $last  = end($parts);
            $key   = $last === '*' ? ($parts[count($parts) - 2] ?? $key) : $last;
        }

        if (isset(self::DICTIONARY[$key])) {
            return self::DICTIONARY[$key];
        }
        if (isset(self::DICTIONARY[$field])) {
            return self::DICTIONARY[$field];
        }

        $words = Str::of($key)->snake()->replace('_', ' ');

        if (str_ends_with($key, '_id')) {
            $base = Str::of($key)->beforeLast('_id')->replace('_', ' ');
            return "ID {$base} (referensi ke resource {$base}).";
        }
        if (str_ends_with($key, '_at')) {
            $base = Str::of($key)->beforeLast('_at')->replace('_', ' ');
            return "Timestamp {$base} (ISO 8601).";
        }
        if (str_starts_with($key, 'is_') || str_starts_with($key, 'has_')) {
            return "Flag boolean untuk {$words}.";
        }

        return "Field {$words} (tipe {$type}).";
    }
}
