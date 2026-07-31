<?php

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
