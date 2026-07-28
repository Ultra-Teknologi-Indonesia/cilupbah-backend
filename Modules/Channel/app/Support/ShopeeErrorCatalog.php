<?php

namespace Modules\Channel\Support;

class ShopeeErrorCatalog
{
    public const TOKEN = 'token';
    public const RETRYABLE = 'retryable';
    public const USER_FIXABLE = 'user_fixable';
    public const FATAL = 'fatal';

    protected const MAP = [

        'error_network' => [self::RETRYABLE, 'Koneksi ke Shopee gagal. Coba lagi.'],
        'error_system_busy' => [self::RETRYABLE, 'Shopee sedang sibuk. Coba lagi beberapa saat lagi.'],
        'error_get_shop_fail' => [self::RETRYABLE, 'Gagal memuat data toko dari Shopee. Coba lagi.'],
        'error_data' => [self::RETRYABLE, 'Shopee gagal memproses data. Coba lagi.'],
        'err_data' => [self::RETRYABLE, 'Shopee gagal memproses data. Coba lagi.'],
        'error_update_price_fail' => [self::RETRYABLE, 'Gagal memperbarui harga. Coba lagi nanti.'],
        'error_unlist_item_failed' => [self::RETRYABLE, 'Gagal menonaktifkan produk. Coba lagi nanti.'],
        'error_unlist_item_all_failed' => [self::RETRYABLE, 'Semua produk gagal dinonaktifkan. Coba lagi nanti.'],
        'error_boost_item_failed' => [self::RETRYABLE, 'Gagal menaikkan produk ke atas. Coba lagi nanti.'],
        'error_boost_item_all_failed' => [self::RETRYABLE, 'Semua produk gagal dinaikkan ke atas. Coba lagi nanti.'],
        'error_get_parnter_token_failed' => [self::RETRYABLE, 'Gagal terhubung ke Shopee. Coba lagi.'],
        'error_model_empty_tier_index_nonempty' => [self::RETRYABLE, 'Terjadi kesalahan di sisi Shopee. Coba lagi atau hubungi Shopee.'],
        'error_marshal' => [self::RETRYABLE, 'Terjadi kesalahan di sisi Shopee. Coba lagi atau hubungi Shopee.'],
        'error_category_level' => [self::RETRYABLE, 'Terjadi kesalahan di sisi Shopee. Coba lagi atau hubungi Shopee.'],
        'error_category_path_count_limit' => [self::RETRYABLE, 'Terjadi kesalahan di sisi Shopee. Coba lagi atau hubungi Shopee.'],
        'error_whole_sale_min_count_incorrect' => [self::RETRYABLE, 'Terjadi kesalahan di sisi Shopee. Coba lagi atau hubungi Shopee.'],
        'error_query_condition_list_limit' => [self::RETRYABLE, 'Terjadi kesalahan di sisi Shopee. Coba lagi atau hubungi Shopee.'],
        'error_query_query_limit_too_large' => [self::RETRYABLE, 'Permintaan terlalu besar. Kurangi jumlah produk lalu coba lagi.'],
        'error_query_over_itemid_size' => [self::RETRYABLE, 'Terlalu banyak produk dalam satu permintaan. Kurangi jumlahnya.'],
        'error_nil_item_in_req' => [self::RETRYABLE, 'Terjadi kesalahan di sisi Shopee. Coba lagi atau hubungi Shopee.'],
        'error_slash_price_load' => [self::RETRYABLE, 'Gagal memuat harga promo. Coba lagi.'],
        'error_cannot_delete_item' => [self::RETRYABLE, 'Gagal menghapus produk. Coba lagi nanti.'],
        'error_busi_add_item_failed' => [self::RETRYABLE, 'Gagal menambahkan produk. Coba lagi. :detail'],
        'error_busi_update_item_failed' => [self::RETRYABLE, 'Gagal memperbarui produk. Coba lagi nanti.'],

        'error_param' => [self::USER_FIXABLE, 'Data yang dikirim tidak sesuai: :detail'],
        'error_param_validate' => [self::USER_FIXABLE, 'Data tidak sesuai: :detail'],
        'error_busi_cannot_update_field' => [self::USER_FIXABLE, 'Gagal memperbarui produk: :detail'],
        'error_check_luc_fail' => [self::USER_FIXABLE, 'Produk tidak lolos pemeriksaan Shopee: :detail'],

        'error_invalid_days_to_ship' => [self::USER_FIXABLE, 'Waktu proses pesanan tidak sesuai.'],
        'error_param_dts_exceeds_max_limit' => [self::USER_FIXABLE, 'Waktu proses pesanan melebihi batas maksimum.'],
        'error_estimated_days_limit' => [self::USER_FIXABLE, 'Waktu proses pesanan melebihi batas.'],
        'error_category_dts' => [self::USER_FIXABLE, 'Waktu proses pesanan melebihi batas maksimum untuk kategori ini.'],

        'error_value_name_required' => [self::USER_FIXABLE, 'Pilihan atribut produk wajib diisi.'],
        'error_value_id_must_equal_zero' => [self::USER_FIXABLE, 'Nilai atribut produk tidak sesuai.'],

        'error_invalid_category' => [self::USER_FIXABLE, 'Kategori produk tidak sesuai: :detail'],
        'error_incalid_category' => [self::USER_FIXABLE, 'Kategori dan subkategori tidak cocok.'],
        'error_invalid_category_attribute' => [self::USER_FIXABLE, 'Kategori dan atribut produk tidak cocok.'],
        'error_category_is_block' => [self::USER_FIXABLE, 'Kategori ini dibatasi oleh Shopee.'],
        'error_forbidden_category' => [self::USER_FIXABLE, 'Kategori ini tidak diperbolehkan.'],
        'error_category' => [self::USER_FIXABLE, 'Kategori tidak sesuai atau tidak diperbolehkan.'],

        'error_invalid_brand' => [self::USER_FIXABLE, 'Merek tidak sesuai atau informasi merek belum lengkap.'],
        'error_incalid_brand' => [self::USER_FIXABLE, 'Merek tidak sesuai: :detail'],
        'error_duplicated_brand' => [self::USER_FIXABLE, 'Merek sudah ada (ganda).'],
        'error_less_required_brand' => [self::USER_FIXABLE, 'Informasi merek wajib dilengkapi.'],
        'error_brand_forbidden' => [self::USER_FIXABLE, 'Merek ini tidak diperbolehkan.'],
        'error_brand' => [self::USER_FIXABLE, 'Merek tidak sesuai. Gunakan merek yang cocok untuk kategori ini.'],

        'error_invalid_attribute' => [self::USER_FIXABLE, 'Atribut produk yang wajib belum lengkap.'],
        'error_less_required_attribute' => [self::USER_FIXABLE, 'Atribut produk yang wajib belum lengkap.'],
        'error_invalid_attribute_value' => [self::USER_FIXABLE, 'Nilai atribut produk tidak sesuai.'],
        'error_wrong_attrsnapshot' => [self::USER_FIXABLE, 'Atribut produk tidak sesuai.'],
        'error_busi_attribute_error' => [self::USER_FIXABLE, 'Atribut produk tidak sesuai atau wajib diisi: :detail'],
        'error_attribute_fda_error' => [self::USER_FIXABLE, 'Nilai atribut sertifikasi (FDA) tidak sesuai.'],
        'error_attribute' => [self::USER_FIXABLE, 'Atribut produk tidak sesuai. Isi atribut sesuai kategori dengan nilai yang benar.'],

        'error_invalid_price' => [self::USER_FIXABLE, 'Harga tidak sesuai. Gunakan format harga yang benar.'],
        'error_price_exceed_min_limitt' => [self::USER_FIXABLE, 'Harga di bawah batas minimum yang diizinkan.'],
        'error_price_exceed_max_limitt' => [self::USER_FIXABLE, 'Harga di atas batas maksimum yang diizinkan.'],
        'error_invalid_price_for_logistic' => [self::USER_FIXABLE, 'Harga terlalu tinggi sehingga opsi pengiriman tidak bisa diaktifkan.'],
        'error_wholesale_price_less_than_ratio_limit' => [self::USER_FIXABLE, 'Harga grosir terlalu rendah dibanding harga normal.'],
        'error_whole_sale_price_setting_incorrect' => [self::USER_FIXABLE, 'Harga grosir tidak boleh lebih tinggi dari harga normal.'],
        'error_slash_price_not_lowest' => [self::USER_FIXABLE, 'Pada promo Potong Harga, harga tidak boleh sama atau lebih rendah dari harga promo.'],

        'error_repeated_mtsku' => [self::USER_FIXABLE, 'Produk serupa sudah pernah diunggah.'],
        'error_name_length_limit' => [self::USER_FIXABLE, 'Nama produk terlalu panjang.'],
        'error_title_exceeds_max_length' => [self::USER_FIXABLE, 'Nama produk melebihi batas panjang maksimum.'],
        'error_item_name_is_too_short' => [self::USER_FIXABLE, 'Nama produk terlalu pendek.'],
        'error_item_name_empty' => [self::USER_FIXABLE, 'Nama produk tidak boleh kosong.'],
        'error_nil_name_new_item' => [self::USER_FIXABLE, 'Nama produk tidak boleh kosong.'],
        'error_title_character_forbidden' => [self::USER_FIXABLE, 'Nama produk mengandung karakter yang tidak diperbolehkan.'],
        'error_desc_length_min_limit' => [self::USER_FIXABLE, 'Deskripsi produk terlalu pendek.'],
        'error_desc_hash_tag_over_limit' => [self::USER_FIXABLE, 'Jumlah tagar (hashtag) tidak boleh lebih dari 18.'],
        'error_image_num_min' => [self::USER_FIXABLE, 'Jumlah gambar produk kurang dari minimum: :detail'],
        'error_image_unavailable' => [self::USER_FIXABLE, 'Gambar tidak valid atau rusak. Unggah ulang gambar produk.'],
        'error_desc_image_no_pass' => [self::USER_FIXABLE, 'Gambar deskripsi ditolak Shopee: :detail'],

        'error_invalid_logistic_info' => [self::USER_FIXABLE, 'Informasi pengiriman tidak sesuai: :detail'],
        'error_param_category_not_support_pre_order' => [self::USER_FIXABLE, 'Kategori ini tidak mendukung pre-order.'],
        'error_video_info_not_found' => [self::USER_FIXABLE, 'Video produk tidak ditemukan.'],
        'error_reach_shop_item_limit' => [self::USER_FIXABLE, 'Jumlah produk aktif sudah mencapai batas maksimum toko.'],
        'error_invalid_language' => [self::USER_FIXABLE, 'Bahasa tidak sesuai.'],
        'error_unlist_item_fail' => [self::USER_FIXABLE, 'Unggah produk dalam status nonaktif; produk akan otomatis tampil saat tanggal peluncuran.'],
        'error_update_time_range' => [self::USER_FIXABLE, 'Rentang tanggal tidak sesuai: tanggal akhir harus setelah tanggal awal.'],
        'error_param_item_status' => [self::USER_FIXABLE, 'Filter status produk tidak sesuai.'],

        'error_tier_var_too_many' => [self::USER_FIXABLE, 'Jumlah variasi produk melebihi batas (maksimal 50).'],
        'error_tier_opt_too_many' => [self::USER_FIXABLE, 'Jumlah pilihan variasi melebihi batas (maksimal 20).'],
        'error_tier_opt_val_too_long' => [self::USER_FIXABLE, 'Nama pilihan variasi terlalu panjang: :detail'],
        'error_tier_var_name_too_long' => [self::USER_FIXABLE, 'Nama variasi terlalu panjang: :detail'],
        'error_model_tier_index_bound' => [self::USER_FIXABLE, 'Variasi produk tidak sesuai.'],
        'error_model_count_over_limit' => [self::USER_FIXABLE, 'Jumlah varian terlalu banyak (maksimal 20; 50 untuk Taiwan).'],
        'error_model_nonempty_itemtier_empty_index' => [self::USER_FIXABLE, 'Variasi produk wajib diisi.'],
        'error_model_duplicate_name' => [self::USER_FIXABLE, 'Ada nama varian yang sama (ganda).'],
        'error_model_duplicate_tier_variation_index' => [self::USER_FIXABLE, 'Ada variasi produk yang sama (ganda).'],

        'error_price_should_be_same_for_wholesales' => [self::USER_FIXABLE, 'Saat harga grosir aktif, semua varian harus berharga sama.'],
        'error_busi_price_lower_then_wholesale_price' => [self::USER_FIXABLE, 'Harga normal harus lebih tinggi dari harga grosir.'],
        'error_price_out_of_range' => [self::USER_FIXABLE, 'Harga di luar rentang yang diizinkan.'],
        'error_slash_price_models_diff' => [self::USER_FIXABLE, 'Pada promo Potong Harga, semua varian harus berharga sama.'],
        'error_edit_item_price_for_item_has_model' => [self::USER_FIXABLE, 'Produk ini punya varian. Ubah harga pada masing-masing varian, bukan pada produk utama.'],
        'error_in_item_promotion_nomodel_to_models' => [self::USER_FIXABLE, 'Produk ini punya varian. Ubah stok pada masing-masing varian, bukan pada produk utama.'],
        'error_edit_item_stock_for_item_has_model' => [self::USER_FIXABLE, 'Produk ini punya varian. Ubah stok pada masing-masing varian, bukan pada produk utama.'],
        'error_busi_update_stock_failed' => [self::USER_FIXABLE, 'Sebagian stok gagal diperbarui. Periksa daftar produk yang gagal untuk detailnya.'],
        'error_set_normal_unlisted_item' => [self::USER_FIXABLE, 'Produk nonaktif tidak bisa langsung ditampilkan. Aktifkan kembali produk yang dinonaktifkan terlebih dahulu.'],

        'error_item_not_belong_shop' => [self::FATAL, 'Produk ini bukan milik toko Anda.'],
        'error_cnsc_shop_block_update_tier_variation' => [self::FATAL, 'Toko Anda tidak bisa mengubah variasi produk ini.'],
        'error_wms_shop_block_upate_stock' => [self::FATAL, 'Stok produk ini dikelola sistem gudang Shopee dan tidak bisa diubah dari sini.'],
        'error_busi_cannot_delist_reviewing_or_banned_item' => [self::FATAL, 'Produk yang diblokir atau sedang ditinjau tidak bisa dinonaktifkan.'],
        'error_related_product_in_promotion' => [self::FATAL, 'Produk sedang dalam promosi. Perbarui harga melalui produk toko, bukan produk global.'],
        'error_in_item_promotion_item_price_lock' => [self::FATAL, 'Harga terkunci karena produk sedang promosi.'],
        'error_cannot_update_price_in_promotion' => [self::FATAL, 'Harga tidak bisa diubah karena varian sedang promosi.'],
        'error_cannt_edit_price_in_promotion' => [self::FATAL, 'Harga tidak bisa diubah karena produk sedang promosi.'],
        'error_cannt_init_tier_in_promotion' => [self::FATAL, 'Variasi produk tidak bisa diubah karena produk sedang promosi.'],
        'error_cannt_be_no_variation_in_promotion' => [self::FATAL, 'Produk tidak bisa dibuat tanpa varian karena sedang promosi.'],
        'error_cannt_delete_option_in_promotion' => [self::FATAL, 'Pilihan variasi tidak bisa dihapus karena produk sedang promosi.'],
        'error_cannt_change_tier_variation_in_promotion' => [self::FATAL, 'Susunan variasi produk tidak bisa diubah karena produk sedang promosi.'],
        'error_cannt_edit_stock_in_promotion' => [self::FATAL, 'Stok tidak bisa diubah karena produk sedang promosi.'],
        'error_holiday_mode_change_stock' => [self::FATAL, 'Stok tidak bisa diubah saat toko dalam mode libur.'],
        'error_promotion_cantnot_update_stock' => [self::FATAL, 'Stok tidak bisa diubah karena produk sedang promosi.'],
        'error_model_update_stock_model_in_promotion' => [self::FATAL, 'Stok varian tidak bisa diubah karena produk atau varian sedang promosi.'],
        'error_cannt_unlisted_in_promotion' => [self::FATAL, 'Produk tidak bisa dinonaktifkan karena sedang promosi.'],
        'error_unlist_in_promotion' => [self::FATAL, 'Produk tidak bisa dinonaktifkan karena sedang promosi.'],
        'error_shop' => [self::FATAL, 'Toko tidak valid.'],
        'error_shop_not_found' => [self::FATAL, 'Toko tidak ditemukan di Shopee.'],
        'error_auth_shop_not_found' => [self::FATAL, 'Toko tidak ditemukan di Shopee.'],
        'error_param_shop_id_not_found' => [self::FATAL, 'Toko tidak ditemukan.'],
        'error_item_not_found' => [self::FATAL, 'Produk tidak ditemukan di Shopee.'],
        'error_item_or_variation_not_found' => [self::FATAL, 'Produk atau varian tidak ditemukan di Shopee.'],
        'error_nil_shopid_or_itemid' => [self::FATAL, 'Permintaan gagal karena data toko atau produk kosong.'],
        'error_busi_invalid_shop_status' => [self::FATAL, 'Status toko tidak valid.'],
        'error_busi_invalid_account_status' => [self::FATAL, 'Status akun tidak valid.'],
        'error_seller_under_penalty' => [self::FATAL, 'Toko sedang dalam masa penalti Shopee.'],
        'error_perm_non_admin' => [self::FATAL, 'Anda tidak punya izin untuk melakukan tindakan ini.'],
        'error_busi_cannot_edit_vsku' => [self::FATAL, 'Produk ini tidak bisa dibuat atau diubah dari aplikasi. Hubungi manajer Shopee Anda.'],
        'error_auth_product_is_pff' => [self::FATAL, 'Produk ini gudang dan pengirimannya dikelola Shopee, jadi tidak bisa diubah dari sini.'],
        'error_item_uneditable' => [self::FATAL, 'Produk tidak bisa diubah pada status saat ini.'],
        'error_busi_item_status_invalid' => [self::FATAL, 'Status produk tidak valid.'],
        'error_busi_cannot_delete_default_model' => [self::FATAL, 'Varian utama tidak bisa dihapus.'],

        'error_cannt_edit_name_in_promotion' => [self::FATAL, 'Nama produk tidak bisa diubah karena produk sedang promosi.'],
        'error_cannt_edit_description_in_promotion' => [self::FATAL, 'Deskripsi tidak bisa diubah karena produk sedang promosi.'],
        'error_cannt_edit_image_in_promotion' => [self::FATAL, 'Gambar tidak bisa diubah karena produk sedang promosi.'],
        'error_cannt_edit_pre_order_in_promotion' => [self::FATAL, 'Pengaturan pre-order tidak bisa diubah karena produk sedang promosi.'],
        'error_cannt_edit_estimated_days_in_promotion' => [self::FATAL, 'Waktu proses pesanan tidak bisa diubah karena produk sedang promosi.'],
        'error_item_in_promotion' => [self::FATAL, 'Kategori tidak bisa diubah karena produk sedang promosi.'],
        'error_in_item_promotion_image_item_lock' => [self::FATAL, 'Gambar terkunci karena produk sedang promosi.'],
        'error_in_item_promotion_name_item_lock' => [self::FATAL, 'Nama produk terkunci karena produk sedang promosi.'],
        'error_in_item_promotion_unlsit_lock' => [self::FATAL, 'Produk tidak bisa dinonaktifkan karena sedang promosi.'],
        'error_in_item_promotion_description_lock' => [self::FATAL, 'Deskripsi terkunci karena produk sedang promosi.'],
        'error_flash_sale_days_to_ship_lock' => [self::FATAL, 'Waktu proses pesanan terkunci karena ada Flash Sale yang sedang atau akan berjalan.'],
        'error_cannt_delete_in_promotion' => [self::FATAL, 'Produk tidak bisa dihapus karena sedang promosi.'],
        'error_in_item_promotion_delete_lock' => [self::FATAL, 'Produk tidak bisa dihapus karena sedang promosi.'],
        'error_in_model_promotion_delete_lock' => [self::FATAL, 'Produk tidak bisa dihapus karena varian sedang promosi.'],
        'error_slash_price_item_delete_lock' => [self::FATAL, 'Produk tidak bisa dihapus saat promo Potong Harga berlangsung.'],
        'error_holiday_on_add_item' => [self::FATAL, 'Toko sedang dalam mode libur. Nonaktifkan mode libur untuk menambah produk.'],
        'error_holiday_on_del_item' => [self::FATAL, 'Toko sedang dalam mode libur. Nonaktifkan mode libur untuk menghapus produk.'],

        'error_unknown' => [self::FATAL, 'Permintaan ditolak Shopee: :detail'],
    ];

    protected const TOKEN_CODES = [
        'error_token_expired',
        'invalid_access_token',
        'access_token_error',
    ];

    protected const TOKEN_MESSAGE = 'Koneksi ke Shopee terputus. Sistem akan menyambungkan ulang.';

    public static function resolve(string $rawCode, ?string $rawMessage = null, ?string $errorInfo = null): array
    {
        $code = self::normalize($rawCode);
        $message = trim((string) $rawMessage);
        $detail = trim((string) ($errorInfo !== null && $errorInfo !== '' ? $errorInfo : $rawMessage));

        if (in_array($code, self::TOKEN_CODES, true)) {
            return self::pack($code, self::TOKEN, self::TOKEN_MESSAGE, $message, $detail);
        }

        $overloaded = self::resolveOverloaded($code, $message);
        if ($overloaded !== null) {
            return self::pack($code, $overloaded[0], self::fill($overloaded[1], $detail), $message, $detail);
        }

        if (isset(self::MAP[$code])) {
            [$category, $template] = self::MAP[$code];

            return self::pack($code, $category, self::fill($template, $detail), $message, $detail);
        }

        if (str_contains($code, 'token')) {
            return self::pack($code, self::TOKEN, self::TOKEN_MESSAGE, $message, $detail);
        }

        $fallback = $detail !== ''
            ? 'Permintaan ditolak Shopee: ' . $detail
            : 'Permintaan ditolak Shopee. Coba lagi atau hubungi Shopee.';

        return self::pack($code, self::FATAL, $fallback, $message, $detail);
    }

    protected static function resolveOverloaded(string $code, string $message): ?array
    {
        $l = mb_strtolower($message);

        if ($code === 'error_server') {
            if (str_contains($l, 'fbs') || str_contains($l, 'normal stock')) {
                return [self::USER_FIXABLE, 'Produk ini gudang dan pengirimannya dikelola Shopee, jadi stok di gudang Anda harus 0.'];
            }

            return [self::RETRYABLE, 'Shopee sedang bermasalah. Coba lagi nanti.'];
        }

        if ($code === 'error_auth') {
            if ($message === '' || str_contains($l, 'token') || str_contains($l, 'access')) {
                return [self::TOKEN, self::TOKEN_MESSAGE];
            }
            if (str_contains($l, 'phone number')) {
                return [self::FATAL, 'Nomor telepon toko bermasalah. Perbarui nomor telepon toko Anda di Shopee.'];
            }
            if (str_contains($l, 'upgrading')) {
                return [self::FATAL, 'Toko sedang dalam proses peningkatan (upgrade) dan belum bisa dioperasikan.'];
            }
            if (str_contains($l, 'reserved') || str_contains($l, 'stock')) {
                return [self::USER_FIXABLE, 'Total stok harus lebih besar dari stok yang sedang dipesan pembeli.'];
            }
            if (str_contains($l, 'location_id')) {
                return [self::USER_FIXABLE, 'Lokasi gudang tidak sesuai dengan pengaturan toko. Periksa kembali lokasi gudang Anda.'];
            }
            if (str_contains($l, 'holiday') || str_contains($l, 'vacation') || str_contains($l, 'vocation')) {
                return [self::FATAL, 'Toko sedang dalam mode libur. Nonaktifkan mode libur terlebih dahulu untuk mengubah produk.'];
            }
            if (str_contains($l, 'model level dts')) {
                return [self::FATAL, 'Toko Anda belum mendukung pengaturan waktu proses pesanan yang berbeda per varian.'];
            }
            if (str_contains($l, 'cnsc')) {
                return [self::FATAL, 'Toko Anda belum bisa mengubah produk ini. Hubungi Shopee.'];
            }
            if (str_contains($l, 'fbs') || str_contains($l, 'b2c')) {
                return [self::USER_FIXABLE, 'Produk ini gudang dan pengirimannya dikelola Shopee, jadi stok di gudang Anda harus 0.'];
            }

            return [self::FATAL, 'Permintaan ditolak Shopee: :detail'];
        }

        if ($code === 'product_error_busi' || $code === 'error_busi') {
            if (str_contains($l, 'gtin')) {
                return [self::USER_FIXABLE, 'Kode barcode produk (GTIN) wajib diisi. Periksa lalu unggah ulang.'];
            }
            if (str_contains($l, 'ts mark') || str_contains($l, 'td mark')) {
                return [self::USER_FIXABLE, 'Sertifikat TS/TD produk wajib diisi dengan benar.'];
            }
            if (str_contains($l, 'maximum purchase')) {
                return [self::USER_FIXABLE, 'Untuk obat bebas, batas maksimal pembelian per pesanan wajib diisi (maksimal untuk 3 hari pemakaian).'];
            }
            if (str_contains($l, 'medicine')) {
                return [self::USER_FIXABLE, 'Nomor izin obat wajib diisi dengan benar untuk kategori obat.'];
            }
            if (str_contains($l, 'size chart')) {
                return [self::USER_FIXABLE, 'Gambar tabel ukuran belum sesuai standar. Unggah ulang gambar yang lebih jelas.'];
            }
            if (str_contains($l, 'tax')) {
                return [self::USER_FIXABLE, 'Informasi pajak wajib diisi.'];
            }
            if (str_contains($l, 'logistic must be free')) {
                return [self::USER_FIXABLE, 'Pengiriman untuk produk ini harus diatur gratis.'];
            }
            if (str_contains($l, 'multi warehouse') || str_contains($l, 'location id')) {
                return [self::USER_FIXABLE, 'Toko Anda punya lebih dari satu gudang. Pilih lokasi gudang terlebih dahulu.'];
            }
            if (str_contains($l, 'wholesale')) {
                return [self::USER_FIXABLE, 'Pengaturan harga grosir tidak sesuai.'];
            }

            return [self::USER_FIXABLE, 'Produk ditolak Shopee: :detail'];
        }

        if ($code === 'error_inner') {
            if (str_contains($l, 'stock location')) {
                return [self::USER_FIXABLE, 'Lokasi gudang tidak sesuai.'];
            }

            return [self::RETRYABLE, 'Shopee sedang lambat merespons. Coba lagi nanti.'];
        }

        return null;
    }

    protected static function pack(string $code, string $category, string $message, string $rawMessage, string $detail): array
    {
        return [
            'code' => $code,
            'category' => $category,
            'message' => $message,
            'error_info' => $detail !== '' ? $detail : null,
            'raw_message' => $rawMessage !== '' ? $rawMessage : null,
        ];
    }

    protected static function fill(string $template, string $detail): string
    {
        if (! str_contains($template, ':detail')) {
            return $template;
        }

        $replacement = $detail !== '' ? $detail : 'tidak ada keterangan tambahan';

        return trim(str_replace(':detail', $replacement, $template));
    }

    protected static function normalize(string $rawCode): string
    {
        $code = mb_strtolower(trim($rawCode));
        $code = (string) preg_replace('/[.\s]+/', '_', $code);

        return (string) preg_replace('/^product_(?=error_)/', '', $code);
    }
}
