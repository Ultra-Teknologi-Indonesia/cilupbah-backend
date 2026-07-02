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
        'INVOICE'          => ['category' => 'INVOICE', 'label' => 'Faktur'],
        'ORDER_SHIP'       => ['category' => 'INVOICE', 'label' => 'Faktur'],
        'ORDER_PICK'       => ['category' => 'INVOICE', 'label' => 'Faktur'],
        'ORDER_RESTORE'    => ['category' => 'INVOICE', 'label' => 'Faktur'],
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
        'INVOICE',
        'TRANSFER',
        'REVALUATION',
    ];

    /**
     * Get meta for a single source enum.
     */
    public static function meta(string $source): array
    {
        return self::SOURCES[$source] ?? ['category' => 'OTHER', 'label' => $source];
    }

    /**
     * Filter options untuk FE (dropdown Sumber + Mutasi).
     * Value = comma-separated list of source enums yang termasuk category itu.
     */
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
