<?php

namespace Modules\Sales\Support;

/**
 * Ubah "reason name" pembatalan pembeli dari channel (mis. TikTok mengirim key
 * seperti `ecom_order_to_ship_canceled_reason_better_price`) menjadi teks
 * Bahasa Indonesia yang enak dibaca. Peta mengikuti dokumentasi resmi TikTok
 * Shop "Cancel reasons" — pasar ID (Buyer Initiates Cancel Reason).
 *
 * Nilai yang sudah berupa teks manusia (mis. Shopee `buyer_cancel_reason`)
 * dikembalikan apa adanya.
 */
class BuyerCancelReasonHumanizer
{
    private const MAP = [
        'ecom_order_unpaid_canceled_reason_payment_method_not_available' => 'Metode pembayaran tidak tersedia',
        'buyer_cancel_need_to_input/change_coupon_code'                  => 'Perlu memasukkan/mengubah kode kupon',
        'buyer_cancel_wrong_item_variation_(colour,_size,_etc.)'         => 'Salah varian (warna, ukuran, dll.)',
        'ecom_order_unpaid_canceled_reason_wrong_delivery_info'          => 'Informasi pengiriman salah',
        'ecom_order_to_ship_canceled_reason_wrong_delivery_info'         => 'Informasi pengiriman salah',
        'ecom_order_unpaid_canceled_reason_created_by_mistakes'          => 'Pesanan dibuat karena keliru',
        'ecom_order_to_ship_canceled_reason_created_by_mistakes'         => 'Pesanan dibuat karena keliru',
        'ecom_order_unpaid_canceled_reason_better_price'                 => 'Ada harga lebih murah',
        'ecom_order_to_ship_canceled_reason_better_price'                => 'Ada harga lebih murah',
        'ecom_order_unpaid_canceled_reason_no_longer_needed'            => 'Tidak dibutuhkan lagi',
        'ecom_order_to_ship_canceled_reason_no_longer_needed'           => 'Tidak dibutuhkan lagi',
        'ecom_order_unpaid_canceled_reason_other'                       => 'Lainnya',
        'ecom_order_to_ship_canceled_reason_change_payment_method'      => 'Perlu mengganti metode pembayaran',
        'ecom_order_to_ship_canceled_reason_discount_not_expected'      => 'Diskon tidak sesuai harapan',
        'ecom_order_to_ship_canceled_reason_high_delivery_costs'        => 'Ongkir terlalu mahal',
        'ecom_order_to_ship_canceled_reason_not_shipped_on_time'        => 'Barang tidak dikirim tepat waktu',
        'ecom_order_unpaid_canceled_reason_not_want_pay'               => 'Pembeli tidak jadi membayar',
    ];

    public static function humanize(?string $reason): ?string
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            return null;
        }

        if (isset(self::MAP[$reason])) {
            return self::MAP[$reason];
        }

        // Sudah teks manusia (ada spasi) -> biarkan. Kalau masih tampak seperti
        // key (tanpa spasi), rapikan seadanya agar tak menampilkan key mentah.
        if (str_contains($reason, ' ')) {
            return $reason;
        }

        $pretty = preg_replace('/^(ecom_order_(unpaid|to_ship)_canceled_reason_|buyer_cancel_|seller_cancel_[a-z]+_reason_)/', '', $reason);
        $pretty = trim(str_replace('_', ' ', (string) $pretty));

        return $pretty !== '' ? ucfirst($pretty) : $reason;
    }
}
