<?php

namespace Modules\Sales\Enums;

/**
 * Kode alasan pembatalan INTERNAL (seller-side).
 *
 * Untuk kode marketplace-side (raw dari webhook Shopee/TikTok/Lazada),
 * gunakan kolom terpisah `sales_orders.mp_cancel_reason` yang tetap string bebas.
 * Adapter channel yang bertanggung jawab memetakan (bila mau) ke enum ini.
 */
enum SalesCancelReason: string
{
    case OUT_OF_STOCK           = 'seller_cancel_reason_out_of_stock';
    case OVER_SLA               = 'seller_cancel_reason_over_sla';
    case DAMAGED                = 'seller_cancel_reason_damaged';
    case BUYER_REQUEST          = 'seller_cancel_reason_buyer_request';
    case ADDRESS_UNREACHABLE    = 'seller_cancel_reason_address_unreachable';
    case FRAUD_SUSPECT          = 'seller_cancel_reason_fraud_suspect';
    case PRICE_ERROR            = 'seller_cancel_reason_price_error';
    case SKU_UNAVAILABLE        = 'seller_cancel_reason_sku_unavailable';
    case OTHER                  = 'seller_cancel_reason_other';

    public function label(): string
    {
        return match ($this) {
            self::OUT_OF_STOCK        => 'Stok habis',
            self::OVER_SLA            => 'Lewat SLA',
            self::DAMAGED             => 'Barang rusak',
            self::BUYER_REQUEST       => 'Permintaan pembeli',
            self::ADDRESS_UNREACHABLE => 'Alamat tidak terjangkau',
            self::FRAUD_SUSPECT       => 'Dugaan penipuan',
            self::PRICE_ERROR         => 'Salah harga',
            self::SKU_UNAVAILABLE     => 'SKU tidak tersedia',
            self::OTHER               => 'Lainnya',
        };
    }
}
