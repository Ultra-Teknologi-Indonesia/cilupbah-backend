<?php

/**
 * Alasan pembatalan seller-initiated yang di-hardcode di sisi PLATFORM.
 *
 * Catatan penting: hanya LAZADA yang menyediakan API daftar alasan
 * (/order/failure_reason/get) — itu diambil live, TIDAK ada di sini.
 * Shopee & TikTok TIDAK punya endpoint untuk itu; nilainya enum tetap dari
 * dokumentasi resmi. Ditaruh di config supaya bisa dikoreksi tanpa ubah kode
 * saat platform mengubah daftar yang diterima (drift).
 *
 * Sumber:
 * - Shopee: v2 order.cancel_order cancel_reason (enum). CUSTOMER_REQUEST &
 *   UNDELIVERABLE_AREA (TW/MY) ditolak API untuk seller-cancel market ID.
 * - TikTok: partner.tiktokshop.com/docv2/page/cancel-reasons (Seller Initiates
 *   Cancel, ID market) — beda per status (unpaid vs paid/on_hold).
 */

return [
    'shopee' => [
        ['key' => 'OUT_OF_STOCK', 'label' => 'Stok habis'],
        ['key' => 'COD_NOT_SUPPORTED', 'label' => 'COD tidak didukung'],
    ],

    'tiktok' => [
        'unpaid' => [
            ['key' => 'seller_cancel_unpaid_reason_out_of_stock', 'label' => 'Stok habis'],
            ['key' => 'seller_cancel_unpaid_reason_wrong_price', 'label' => 'Kesalahan harga'],
            ['key' => 'seller_cancel_unpaid_reason_buyer_hasnt_paid_within_time_allowed', 'label' => 'Pembeli belum membayar tepat waktu'],
        ],
        'paid' => [
            ['key' => 'seller_cancel_reason_out_of_stock', 'label' => 'Stok habis'],
            ['key' => 'seller_cancel_reason_wrong_price', 'label' => 'Kesalahan harga'],
            ['key' => 'seller_cancel_paid_reason_address_not_deliver', 'label' => 'Alamat pembeli tidak terjangkau'],
        ],
    ],
];
