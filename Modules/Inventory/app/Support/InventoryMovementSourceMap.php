<?php

namespace Modules\Inventory\Support;

class InventoryMovementSourceMap
{
    public const SOURCES = [
        'BILL'             => ['category' => 'BILL', 'label' => 'Tagihan'],
        'ADJUSTMENT'       => ['category' => 'ADJUSTMENT', 'label' => 'Penyesuaian'],
        'STOCK_OPNAME'     => ['category' => 'ADJUSTMENT', 'label' => 'Penyesuaian'],
        'PURCHASE_RETURN'  => ['category' => 'PURCHASE_RETURN', 'label' => 'Retur Pembelian'],
        'SALES_RETURN'     => ['category' => 'SALES_RETURN', 'label' => 'Retur Penjualan'],
        'PICKING'          => ['category' => 'PICKING', 'label' => 'Barang di-pick'],
        'ORDER_SHIP'       => ['category' => 'PESANAN', 'label' => 'Pesanan Dikirim'],
        'ORDER_RESTORE'    => ['category' => 'PESANAN', 'label' => 'Pesanan Dibatalkan'],
        'INVOICE'          => ['category' => 'INVOICE', 'label' => 'Faktur'],
        'ORDER_PICK'       => ['category' => 'INVOICE', 'label' => 'Faktur'],
        'TRANSFER_IN'      => ['category' => 'TRANSFER', 'label' => 'Transfer'],
        'TRANSFER_OUT'     => ['category' => 'TRANSFER', 'label' => 'Transfer'],
        'BIN_TRANSFER_IN'  => ['category' => 'TRANSFER', 'label' => 'Transfer'],
        'BIN_TRANSFER_OUT' => ['category' => 'TRANSFER', 'label' => 'Transfer'],
        'PUTAWAY_IN'       => ['category' => 'TRANSFER', 'label' => 'Transfer'],
        'PUTAWAY_OUT'      => ['category' => 'TRANSFER', 'label' => 'Transfer'],
        'REVALUATION'      => ['category' => 'REVALUATION', 'label' => 'Ubah Nilai Stok'],
    ];

    private const CATEGORY_ORDER = [
        'BILL',
        'ADJUSTMENT',
        'PURCHASE_RETURN',
        'SALES_RETURN',
        'PICKING',
        'PESANAN',
        'INVOICE',
        'TRANSFER',
        'REVALUATION',
    ];

    // Sumber yang berperan sebagai variance / stok nyangkut. ORDER_PICK ada di sini demi legacy —
    // stop ditulis setelah StockService.pickSingle dinetralkan, tapi row historis tetap terlihat di
    // tab Perlu Perhatian supaya admin bisa telusur stok nyangkut peninggalan.
    public const INVOICE_SOURCES = ['INVOICE', 'ORDER_PICK'];

    // Sumber yang disembunyikan dari tab Kronologi Bersih (default view untuk staf lapangan).
    public const CLEAN_HIDDEN_SOURCES = ['INVOICE', 'ORDER_PICK'];

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
                'label' => $groups[$cat]['label'],
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
