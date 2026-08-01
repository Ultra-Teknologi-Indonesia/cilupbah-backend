<?php

namespace Modules\Inventory\Support;

class InventoryMovementSourceMap
{

    public const SOURCES = [
        'PURCHASE'         => ['category' => 'PURCHASE', 'label' => 'Pembelian'],
        'PURCHASE_REVERSAL' => ['category' => 'PURCHASE', 'label' => 'Koreksi Pembelian'],
        'BILL'             => ['category' => 'PURCHASE', 'label' => 'Pembelian'], 

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
        'ORDER_SHIP'          => ['category' => 'PESANAN', 'label' => 'Pesanan Dikirim'], 
        'ORDER_RESTORE'       => ['category' => 'PESANAN', 'label' => 'Pesanan Dibatalkan'],
        'ORDER_RESTORE_CANCEL' => ['category' => 'PESANAN', 'label' => 'Pesanan Dibatalkan'],
        'INVOICE'          => ['category' => 'INVOICE', 'label' => 'Faktur'],      
        'ORDER_PICK'       => ['category' => 'INVOICE', 'label' => 'Faktur'],      
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

    public const INVOICE_SOURCES = ['INVOICE', 'ORDER_PICK'];

    public const REVERSAL_SOURCES = [
        'PUTAWAY_REVERSAL',
        'BIN_TRANSFER_REVERSAL',
        'TRANSFER_REVERT',
        'PURCHASE_REVERSAL',
        'PICKING_REVERSAL',
    ];

    public const UNRECORDED_REVERSAL_SOURCES = [
        'PUTAWAY_REVERSAL',
        'BIN_TRANSFER_REVERSAL',
        'TRANSFER_REVERT',
    ];

    public const ALLOCATION_PARTITION_SOURCES = [
        'ORDER_RESERVE',
        'ORDER_RELEASE',
        'RESERVE',
        'RESERVE_CANCEL',
        'RESERVE_EXPIRED',
    ];

    public const ORDER_LEDGER_SOURCES = ['ORDER_RESERVE', 'ORDER_RELEASE'];

    public const NON_PHYSICAL_SOURCES = [
        'ORDER_RESERVE',
        'ORDER_RELEASE',
        'RESERVE',
        'RESERVE_CANCEL',
        'RESERVE_EXPIRED',
        'ORDER_SHIP',
    ];

    public const CLEAN_HIDDEN_SOURCES = [
        ...self::INVOICE_SOURCES,
        ...self::NON_PHYSICAL_SOURCES,
    ];

    public const TRANSIT_SOURCES = ['TRANSIT_IN', 'TRANSIT_OUT'];

    public const DRILL_SCOPES = [
        'transit'    => self::TRANSIT_SOURCES,
        'allocation' => self::ALLOCATION_PARTITION_SOURCES,
    ];

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
