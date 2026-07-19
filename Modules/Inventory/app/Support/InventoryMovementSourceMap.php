<?php

namespace Modules\Inventory\Support;

class InventoryMovementSourceMap
{
    /**
     * Setiap nilai `source` yang pernah ditulis produksi WAJIB terdaftar di sini.
     * Yang tidak terdaftar jatuh ke fallback meta() -- tampil sebagai enum mentah
     * dan hilang dari dropdown filter, karena filterOptions() hanya merender
     * kategori yang ada di CATEGORY_ORDER.
     *
     * Beberapa entri sengaja dipertahankan meski kodenya tidak lagi menulisnya
     * (ditandai LEGACY): baris historisnya masih ada di ledger dan tidak boleh
     * kehilangan label.
     */
    public const SOURCES = [
        'PURCHASE'         => ['category' => 'PURCHASE', 'label' => 'Pembelian'],
        'PURCHASE_REVERSAL' => ['category' => 'PURCHASE', 'label' => 'Koreksi Pembelian'],
        'BILL'             => ['category' => 'PURCHASE', 'label' => 'Pembelian'], // LEGACY
        // Digabung ke kategori PURCHASE agar tidak muncul sebagai pilihan filter
        // tersendiri. Labelnya tetap "Konsinyasi" supaya baris yang sudah terlanjur
        // tercatat tidak kehilangan artinya.
        'CONSIGNMENT'      => ['category' => 'PURCHASE', 'label' => 'Konsinyasi'],
        'ADJUSTMENT'       => ['category' => 'ADJUSTMENT', 'label' => 'Penyesuaian'],
        'STOCK_OPNAME'     => ['category' => 'ADJUSTMENT', 'label' => 'Penyesuaian'],
        'SALES_RETURN'     => ['category' => 'SALES_RETURN', 'label' => 'Retur Penjualan'],
        'PICKING'          => ['category' => 'PICKING', 'label' => 'Barang di-pick'],
        'PICKING_REVERSAL' => ['category' => 'PICKING', 'label' => 'Koreksi Pick'],
        'ORDER_RESERVE'       => ['category' => 'ORDER', 'label' => 'Alokasi Pesanan'],
        'ORDER_RELEASE'       => ['category' => 'ORDER', 'label' => 'Alokasi Dilepas'],
        'RESERVE'             => ['category' => 'ORDER', 'label' => 'Stok Ditahan'],
        'RESERVE_CANCEL'      => ['category' => 'ORDER', 'label' => 'Tahanan Dibatalkan'],
        'RESERVE_EXPIRED'     => ['category' => 'ORDER', 'label' => 'Tahanan Kedaluwarsa'],
        'ORDER_SHIP'          => ['category' => 'PESANAN', 'label' => 'Pesanan Dikirim'], // LEGACY
        'ORDER_RESTORE'       => ['category' => 'PESANAN', 'label' => 'Pesanan Dibatalkan'],
        'ORDER_RESTORE_CANCEL' => ['category' => 'PESANAN', 'label' => 'Pesanan Dibatalkan'],
        'INVOICE'          => ['category' => 'INVOICE', 'label' => 'Faktur'],      // LEGACY
        'ORDER_PICK'       => ['category' => 'INVOICE', 'label' => 'Faktur'],      // LEGACY
        'TRANSFER_IN'      => ['category' => 'TRANSFER', 'label' => 'Transfer'],
        'TRANSFER_OUT'     => ['category' => 'TRANSFER', 'label' => 'Transfer'],
        'TRANSFER_REVERT'  => ['category' => 'TRANSFER', 'label' => 'Koreksi Transfer'],
        'TRANSIT_IN'       => ['category' => 'TRANSFER', 'label' => 'Masuk Transit'],
        'TRANSIT_OUT'      => ['category' => 'TRANSFER', 'label' => 'Keluar Transit'],
        'BIN_TRANSFER_IN'  => ['category' => 'TRANSFER', 'label' => 'Transfer'],
        'BIN_TRANSFER_OUT' => ['category' => 'TRANSFER', 'label' => 'Transfer'],
        'BIN_TRANSFER_REVERSAL' => ['category' => 'TRANSFER', 'label' => 'Koreksi Pindah Bin'],
        'PUTAWAY_IN'       => ['category' => 'TRANSFER', 'label' => 'Transfer'],
        'PUTAWAY_OUT'      => ['category' => 'TRANSFER', 'label' => 'Transfer'],
        'PUTAWAY_REVERSAL' => ['category' => 'TRANSFER', 'label' => 'Koreksi Penempatan'],
        'SPLIT_IN'         => ['category' => 'TRANSFER', 'label' => 'Pecah Stok'],
        'SPLIT_OUT'        => ['category' => 'TRANSFER', 'label' => 'Pecah Stok'],
        'REVALUATION'      => ['category' => 'REVALUATION', 'label' => 'Ubah Nilai Stok'],
    ];

    /**
     * Label grup di dropdown filter. Ditulis eksplisit karena mengambil label
     * source pertama menyesatkan: kategori PESANAN jadi bernama "Pesanan Dikirim"
     * padahal isinya didominasi pembatalan (ORDER_SHIP sudah LEGACY).
     */
    private const CATEGORY_LABELS = [
        'PURCHASE'        => 'Pembelian',
        'ADJUSTMENT'      => 'Penyesuaian',
        'SALES_RETURN'    => 'Retur Penjualan',
        'PICKING'         => 'Barang di-pick',
        'ORDER'           => 'Order',
        'PESANAN'         => 'Pesanan Batal',
        'INVOICE'         => 'Faktur',
        'TRANSFER'        => 'Transfer & Penempatan',
        'REVALUATION'     => 'Ubah Nilai Stok',
    ];

    private const CATEGORY_ORDER = [
        'PURCHASE',
        'ADJUSTMENT',
        'SALES_RETURN',
        'PICKING',
        'ORDER',
        'PESANAN',
        'INVOICE',
        'TRANSFER',
        'REVALUATION',
    ];

    /**
     * Tab "Perlu Perhatian" = sinyal variance nyata.
     *
     * Sengaja TIDAK diarahkan ke PICKING: commit 647876d1 justru menjadikan PICKING
     * sumber tunggal on_hand supaya tab ini berhenti jadi noise per-pesanan.
     * Isinya kini murni LEGACY -- baris pra-refactor yang memang layak ditinjau.
     * Kode sekarang tidak menulis satu pun dari keduanya, jadi untuk data baru tab
     * ini wajar kosong.
     */
    public const INVOICE_SOURCES = ['INVOICE', 'ORDER_PICK'];

    /**
     * Semua source yang menggerakkan `on_order`, BUKAN `on_hand`.
     *
     * Dipakai untuk mem-partisi running balance di kronologi: baris alokasi tidak
     * boleh ikut dijumlahkan ke saldo fisik. Reserved Stock (RESERVE*) menulis
     * `balance` = on_hand yang TIDAK berubah, jadi kalau ia jatuh ke partisi
     * on-hand, qty-nya mencemari kolom "Sisa".
     */
    public const ALLOCATION_PARTITION_SOURCES = [
        'ORDER_RESERVE',
        'ORDER_RELEASE',
        'RESERVE',
        'RESERVE_CANCEL',
        'RESERVE_EXPIRED',
    ];

    /**
     * Ledger alokasi milik SALES ORDER saja, di-scope per `transaction_number`.
     *
     * Sengaja TIDAK memuat RESERVE* — Reserved Stock adalah dokumen terpisah
     * dengan penomoran sendiri (`reserved_stock_no`), dan mencampurnya akan
     * merusak perhitungan sisa alokasi per pesanan.
     */
    public const ORDER_LEDGER_SOURCES = ['ORDER_RESERVE', 'ORDER_RELEASE'];

    /**
     * Peristiwa yang TIDAK memindahkan barang secara fisik.
     *
     * Alokasi terjadi begitu pesanan masuk, jauh sebelum ada orang menyentuh rak.
     * Menampilkannya di kronologi membuat tim lapangan membaca "ada pergerakan"
     * padahal barang masih utuh di rak -- keluhan utama klien atas sistem lama.
     */
    public const NON_PHYSICAL_SOURCES = [
        'ORDER_RESERVE',
        'ORDER_RELEASE',
        'RESERVE',
        'RESERVE_CANCEL',
        'RESERVE_EXPIRED',
        'ORDER_SHIP',
    ];

    /**
     * Disembunyikan dari kronologi "bersih": peristiwa non-fisik + entri variance
     * legacy (Faktur). Sisanya adalah pergerakan barang yang benar-benar terjadi:
     * picking, pembatalan, retur, penempatan, dan transfer.
     */
    public const CLEAN_HIDDEN_SOURCES = [
        ...self::INVOICE_SOURCES,
        ...self::NON_PHYSICAL_SOURCES,
    ];

    /**
     * Leg stok in-transit. BUKAN TRANSFER_xx maupun BIN_TRANSFER_xx -- dua itu
     * leg gudang-ke-gudang dan pindah-bin, yang justru bukan stok in-transit.
     */
    public const TRANSIT_SOURCES = ['TRANSIT_IN', 'TRANSIT_OUT'];

    /**
     * Scope drill-down Posisi Stok, dikunci di BE supaya FE tidak perlu
     * menyalin daftar source. Dulu FE meng-hardcode daftarnya sendiri dan
     * salah: memfilter TRANSFER_* untuk metrik Transit.
     */
    public const DRILL_SCOPES = [
        'transit'    => self::TRANSIT_SOURCES,
        'allocation' => self::ALLOCATION_PARTITION_SOURCES,
    ];

    /**
     * Perluas token filter jadi daftar source konkret.
     *
     * Menerima nama source (`ORDER_RESERVE`) MAUPUN nama kategori (`ORDER`),
     * sehingga `?filter[source]=ORDER` bekerja seperti di Jubelio -- acuan yang
     * dipakai klien saat membandingkan kedua sistem.
     */
    public static function expandFilterTokens(array $tokens): array
    {
        $sources = [];

        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token === '') {
                continue;
            }

            if (array_key_exists($token, self::SOURCES)) {
                $sources[] = $token;
                continue;
            }

            foreach (self::SOURCES as $source => $meta) {
                if ($meta['category'] === $token) {
                    $sources[] = $source;
                }
            }
        }

        return array_values(array_unique($sources));
    }

    public static function meta(string $source): array
    {
        return self::SOURCES[$source] ?? ['category' => 'OTHER', 'label' => $source];
    }

    public static function isVariance(string $source): bool
    {
        return in_array($source, self::INVOICE_SOURCES, true);
    }

    public static function filterOptions(): array
    {
        $groups = [];
        foreach (self::SOURCES as $source => $meta) {
            $cat = $meta['category'];
            $groups[$cat] ??= ['label' => $meta['label'], 'sources' => []];
            $groups[$cat]['sources'][] = $source;
        }

        $sources = [];
        foreach (self::CATEGORY_ORDER as $cat) {
            if (! isset($groups[$cat])) {
                continue;
            }
            $sources[] = [
                'value' => implode(',', $groups[$cat]['sources']),
                'label' => self::CATEGORY_LABELS[$cat] ?? $groups[$cat]['label'],
                'category' => $cat,
            ];
        }

        return [
            'sources' => $sources,
            'directions' => [
                ['value' => 'in', 'label' => 'Masuk'],
                ['value' => 'out', 'label' => 'Keluar'],
            ],
        ];
    }
}
