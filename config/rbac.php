<?php

return [

    'actions' => [
        'view' => 'Lihat',
        'create' => 'Tambah',
        'edit' => 'Ubah',
        'delete' => 'Hapus',
        'export' => 'Ekspor',
        'import' => 'Impor',
    ],

    'groups' => [

        [
            'key' => 'sistem',
            'label' => 'Sistem',
            'resources' => [
                ['key' => 'dashboard', 'label' => 'Dashboard', 'actions' => ['view']],
                [
                    'key' => 'user', 'label' => 'Pengguna',
                    'actions' => ['view', 'create', 'edit', 'delete', 'export'],
                    'extras' => [
                        ['name' => 'view-user-history', 'label' => 'Lihat Riwayat & Sesi'],
                        ['name' => 'force-logout-user', 'label' => 'Paksa Logout'],
                    ],
                ],
                [
                    'key' => 'role', 'label' => 'Peran & Hak Akses',
                    'actions' => ['view', 'create', 'edit', 'delete'],
                    'extras' => [
                        ['name' => 'view-permission', 'label' => 'Lihat Daftar Hak Akses'],
                    ],
                ],
                ['key' => 'impex', 'label' => 'Aktivitas Import/Export', 'actions' => ['view']],
                ['key' => 'webhook', 'label' => 'Webhook', 'actions' => ['view', 'edit']],
                ['key' => 'pengaturan-sistem', 'label' => 'Pengaturan Sistem', 'actions' => ['view', 'edit']],
                ['key' => 'pajak', 'label' => 'Pajak', 'actions' => ['view', 'edit']],
            ],
        ],

        [
            'key' => 'katalog',
            'label' => 'Katalog',
            'resources' => [
                [
                    'key' => 'produk', 'label' => 'Produk',
                    'actions' => ['view', 'create', 'edit', 'delete', 'export', 'import'],
                    'extras' => [
                        ['name' => 'view-product-merge', 'label' => 'Lihat Gabung Produk'],
                        ['name' => 'auto-merge-product', 'label' => 'Auto Gabung Produk'],
                        ['name' => 'merge-product', 'label' => 'Gabung Produk'],
                        ['name' => 'unmerge-product', 'label' => 'Batal Gabung Produk'],
                        ['name' => 'hide-product', 'label' => 'Sembunyikan Produk'],
                    ],
                ],
                ['key' => 'produk-naik', 'label' => 'Naikkan Produk ke Channel', 'actions' => ['view', 'create', 'edit', 'delete']],
                ['key' => 'pantauan-produk', 'label' => 'Pantauan Produk Channel', 'actions' => ['view']],
                ['key' => 'kategori', 'label' => 'Kategori & Atribut', 'actions' => ['view', 'create', 'edit', 'delete']],
                ['key' => 'merek', 'label' => 'Merek', 'actions' => ['view', 'create', 'edit', 'delete']],
            ],
        ],

        [
            'key' => 'persediaan',
            'label' => 'Persediaan',
            'resources' => [
                ['key' => 'posisi-stok', 'label' => 'Posisi Stok', 'actions' => ['view', 'export']],
                ['key' => 'penyesuaian-stok', 'label' => 'Penyesuaian Stok', 'actions' => ['view', 'create', 'delete', 'export', 'import']],
                ['key' => 'pindah-bin', 'label' => 'Pindah Bin (Transaksi Stok)', 'actions' => ['view', 'create', 'edit', 'delete']],
                ['key' => 'stok-opname', 'label' => 'Stok Opname', 'actions' => ['view', 'create', 'edit']],
                [
                    'key' => 'revaluasi-stok', 'label' => 'Revaluasi Stok',
                    'actions' => ['view', 'create', 'edit'],
                    'extras' => [['name' => 'approve-revaluasi-stok', 'label' => 'Setujui Revaluasi']],
                ],
                ['key' => 'monitor-stok', 'label' => 'Monitor Stok', 'actions' => ['view', 'export']],
                ['key' => 'pengaturan-persediaan', 'label' => 'Pengaturan Persediaan (Sync Stok & Harga)', 'actions' => ['view', 'edit']],
                ['key' => 'harga-jual', 'label' => 'Harga Jual Internal', 'actions' => ['view', 'edit']],
                ['key' => 'bundle', 'label' => 'Bundle', 'actions' => ['view', 'create', 'edit', 'delete']],
            ],
        ],

        [
            'key' => 'penjualan',
            'label' => 'Penjualan',
            'resources' => [
                ['key' => 'pesanan', 'label' => 'Pesanan', 'actions' => ['view', 'create', 'edit', 'delete', 'export', 'import']],
                ['key' => 'retur-penjualan', 'label' => 'Retur Penjualan', 'actions' => ['view', 'create', 'edit', 'delete', 'export']],
                ['key' => 'garansi', 'label' => 'Garansi', 'actions' => ['view', 'edit']],
                ['key' => 'faktur-penjualan', 'label' => 'Faktur Penjualan', 'actions' => ['view', 'create', 'export']],
                ['key' => 'pembayaran-penjualan', 'label' => 'Pembayaran & Settlement Penjualan', 'actions' => ['view', 'create', 'delete']],
                ['key' => 'toko-internal', 'label' => 'Toko Internal', 'actions' => ['view', 'create', 'edit', 'delete']],
                ['key' => 'kontak-pelanggan', 'label' => 'Kontak Pelanggan', 'actions' => ['view', 'create', 'edit', 'delete', 'export', 'import']],
                ['key' => 'integrasi-channel', 'label' => 'Integrasi Channel', 'actions' => ['view', 'create', 'edit', 'delete']],
            ],
        ],

        [
            'key' => 'pembelian',
            'label' => 'Pembelian',
            'resources' => [
                [
                    'key' => 'transaksi-pembelian', 'label' => 'Transaksi Pembelian (PO)',
                    'actions' => ['view', 'create', 'edit', 'delete', 'export'],
                    'extras' => [['name' => 'receive-transaksi-pembelian', 'label' => 'Terima Barang PO']],
                ],
                ['key' => 'retur-pembelian', 'label' => 'Retur Pembelian', 'actions' => ['view', 'create', 'edit', 'delete']],
                ['key' => 'pembayaran-pembelian', 'label' => 'Pembayaran & Tagihan Pembelian', 'actions' => ['view', 'create', 'delete']],
                ['key' => 'kontak-pemasok', 'label' => 'Kontak Pemasok', 'actions' => ['view', 'create', 'edit', 'delete', 'export', 'import']],
                ['key' => 'salesman', 'label' => 'Salesman', 'actions' => ['view', 'create', 'edit', 'delete']],
            ],
        ],

        [
            'key' => 'gudang',
            'label' => 'Gudang',
            'resources' => [
                ['key' => 'barang-masuk', 'label' => 'Barang Masuk (Inbound)', 'actions' => ['view', 'create', 'edit', 'delete']],
                ['key' => 'penempatan', 'label' => 'Penempatan (Putaway)', 'actions' => ['view', 'create', 'edit', 'delete', 'export']],
                ['key' => 'barang-keluar', 'label' => 'Barang Keluar (Transfer)', 'actions' => ['view', 'create', 'edit', 'delete']],
                ['key' => 'picking', 'label' => 'Picking', 'actions' => ['view', 'create', 'edit', 'delete']],
                ['key' => 'packing', 'label' => 'Packing', 'actions' => ['view', 'create', 'edit', 'delete']],
                ['key' => 'pengiriman', 'label' => 'Pengiriman & Serah Terima', 'actions' => ['view', 'create', 'edit', 'delete']],
                ['key' => 'manajemen-rak', 'label' => 'Manajemen Rak & Lokasi', 'actions' => ['view', 'create', 'edit', 'delete']],
                ['key' => 'permintaan-restock', 'label' => 'Permintaan Pengisian Stok', 'actions' => ['view', 'create', 'edit', 'delete']],
                ['key' => 'kurir', 'label' => 'Kurir', 'actions' => ['view', 'create', 'edit', 'delete']],
            ],
        ],

        [
            'key' => 'keuangan',
            'label' => 'Keuangan',
            'resources' => [
                ['key' => 'jurnal', 'label' => 'Jurnal', 'actions' => ['view', 'create']],
                ['key' => 'kas-bank', 'label' => 'Kas & Bank', 'actions' => ['view']],
                ['key' => 'akun', 'label' => 'Akun & Pemetaan Akun', 'actions' => ['view', 'edit']],
            ],
        ],

        [
            'key' => 'laporan',
            'label' => 'Laporan',
            'resources' => [
                ['key' => 'laporan-hpp', 'label' => 'Laporan HPP', 'actions' => ['view', 'export']],
                ['key' => 'laporan-persediaan', 'label' => 'Laporan Persediaan', 'actions' => ['view', 'export']],
                ['key' => 'laporan-pembelian', 'label' => 'Laporan Pembelian', 'actions' => ['view', 'export']],
                ['key' => 'laporan-penjualan', 'label' => 'Laporan Penjualan', 'actions' => ['view', 'export']],
                ['key' => 'laporan-gudang', 'label' => 'Laporan Gudang', 'actions' => ['view', 'export']],
                ['key' => 'laporan-retur', 'label' => 'Laporan Retur', 'actions' => ['view', 'export']],
                ['key' => 'laporan-stok-minus', 'label' => 'Riwayat Stok Minus', 'actions' => ['view', 'export']],
            ],
        ],
    ],

    'defaults' => [
        'admin' => ['*'],

        'kepala gudang' => [
            'group:gudang:*', 'group:persediaan:*',
            'produk:view', 'pesanan:view', 'dashboard:view',
            'laporan-gudang:*', 'laporan-persediaan:*',
        ],

        'leader outbound' => [
            'barang-keluar:*', 'picking:*', 'packing:*', 'pengiriman:*',
            'pesanan:view', 'posisi-stok:view', 'dashboard:view',
            'laporan-gudang:*',
        ],

        'leader inbound' => [
            'barang-masuk:*', 'penempatan:*', 'manajemen-rak:*', 'permintaan-restock:*',
            'produk:view', 'posisi-stok:view', 'dashboard:view',
            'laporan-persediaan:*',
        ],

        'picker' => ['picking:view', 'picking:edit', 'pesanan:view', 'posisi-stok:view', 'dashboard:view'],
        'checker' => ['packing:view', 'packing:edit', 'pesanan:view', 'posisi-stok:view', 'dashboard:view'],
        'handover' => ['pengiriman:view', 'pengiriman:edit', 'pesanan:view', 'dashboard:view'],
        'shipper' => ['pengiriman:view', 'pengiriman:create', 'pengiriman:edit', 'pesanan:view', 'dashboard:view'],
        'putaway' => ['penempatan:*', 'barang-masuk:view', 'manajemen-rak:view', 'posisi-stok:view', 'dashboard:view'],

        'purchasing' => [
            'group:pembelian:*',
            'produk:view', 'produk:create', 'produk:edit',
            'posisi-stok:view', 'dashboard:view', 'laporan-pembelian:*',
        ],

        'warehouse' => [
            'group:persediaan:*', 'barang-masuk:*', 'penempatan:*', 'manajemen-rak:*',
            'produk:view', 'dashboard:view',
        ],

        'cs marketplace' => [
            'pesanan:view', 'pesanan:edit',
            'retur-penjualan:*', 'kontak-pelanggan:*',
            'integrasi-channel:view', 'dashboard:view',
        ],
    ],
];
