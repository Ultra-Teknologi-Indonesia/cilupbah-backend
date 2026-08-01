<?php

namespace Modules\Sales\Support;

class CancelReasonHumanizer
{
    private const BUYER_MAP = [
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

    private const SELLER_REJECT_MAP = [
        'order_manage_list_action_respond_popup_reject_reason_invalid_cancellation_reason' => 'Alasan pembatalan tidak valid',
        'order_manage_list_action_respond_popup_reject_reason_delivered'                   => 'Pengiriman sesuai jadwal',
        'order_manage_list_action_respond_popup_reject_reason_buyer_agree'                 => 'Sudah sepakat dengan pembeli',
        'seller_reject_apply_product_has_been_packed'                                      => 'Produk sudah dikemas',
        'seller_reject_apply_package_has_not_exceeded_estimated_delivery_time'             => 'Pengiriman masih dalam estimasi',
        'seller_reject_apply_unable_to_change_address'                                     => 'Tidak bisa mengubah alamat',
        'seller_reject_apply_item_is_correct'                                              => 'Barang sudah benar',
        'seller_reject_apply_you_have_reached_an_agreement_with_the_buyer'                 => 'Sudah sepakat dengan pembeli',
        'reverse_reject_request_reason_5'                                                  => 'Sudah sepakat dengan pembeli',
    ];

    public static function buyer(?string $reason): ?string
    {
        return self::map(self::BUYER_MAP, $reason);
    }

    public static function sellerReject(?string $reason): ?string
    {
        return self::map(self::SELLER_REJECT_MAP, $reason);
    }

    private static function map(array $table, ?string $reason): ?string
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            return null;
        }

        if (isset($table[$reason])) {
            return $table[$reason];
        }

        if (str_contains($reason, ' ')) {
            return $reason;
        }

        $pretty = preg_replace(
            '/^(ecom_order_(unpaid|to_ship)_canceled_reason_|buyer_cancel_|seller_reject_apply_|seller_cancel_[a-z]+_reason_|order_manage_list_action_respond_popup_reject_reason_)/',
            '',
            $reason,
        );
        $pretty = trim(str_replace('_', ' ', (string) $pretty));

        return $pretty !== '' ? ucfirst($pretty) : $reason;
    }
}
