<?php

namespace Modules\Inventory\Support;

class InventoryMovementSourceMap
{
    public const HIDDEN_SOURCES = [
        'INBOUND_QTY_CORRECTION',
    ];

    public const SOURCES = [
        'INBOUND_QTY_CORRECTION' => ['category' => 'PENYESUAIAN', 'label' => 'Koreksi Penerimaan'],
        'PURCHASE' => ['category' => 'TAGIHAN', 'label' => 'Tagihan'],
        'PURCHASE_REVERSAL' => ['category' => 'TAGIHAN', 'label' => 'Koreksi Tagihan'],
        'BILL' => ['category' => 'TAGIHAN', 'label' => 'Tagihan'],
        'CONSIGNMENT' => ['category' => 'TAGIHAN', 'label' => 'Konsinyasi'],

        'ADJUSTMENT' => ['category' => 'PENYESUAIAN', 'label' => 'Penyesuaian'],
        'STOCK_OPNAME' => ['category' => 'PENYESUAIAN', 'label' => 'Penyesuaian'],
        'REVALUATION' => ['category' => 'PENYESUAIAN', 'label' => 'Ubah Nilai Stok'],

        'PURCHASE_RETURN' => ['category' => 'RETUR_PEMBELIAN', 'label' => 'Retur Pembelian'],
        'SALES_RETURN' => ['category' => 'RETUR_PENJUALAN', 'label' => 'Retur Penjualan'],

        'INVOICE' => ['category' => 'FAKTUR', 'label' => 'Faktur'],
        'ORDER_SHIP' => ['category' => 'FAKTUR', 'label' => 'Faktur'],
        'ORDER_PICK' => ['category' => 'FAKTUR', 'label' => 'Faktur'],
        'ORDER_COMPLETE_OUT' => ['category' => 'FAKTUR', 'label' => 'Faktur'],
        'BACKFILL_INBOUND_RESTORE' => ['category' => 'PENYESUAIAN', 'label' => 'Koreksi Backfill'],
        'ORDER_COMPLETE_REVERSAL' => ['category' => 'FAKTUR', 'label' => 'Koreksi Faktur'],
        'PICKING' => ['category' => 'FAKTUR', 'label' => 'Barang di-pick'],
        'PICKING_REVERSAL' => ['category' => 'FAKTUR', 'label' => 'Koreksi Pick'],

        'ORDER' => ['category' => 'PESANAN', 'label' => 'Pesanan'],
        'ORDER_RESERVE' => ['category' => 'PESANAN', 'label' => 'Pesanan'],
        'ORDER_RELEASE' => ['category' => 'PESANAN_BATAL', 'label' => 'Pesanan Batal'],

        'TRANSFER_IN' => ['category' => 'TRANSFER', 'label' => 'Transfer'],
        'TRANSFER_OUT' => ['category' => 'TRANSFER', 'label' => 'Transfer'],
        'TRANSFER_REVERT' => ['category' => 'TRANSFER', 'label' => 'Koreksi Transfer'],
        'TRANSIT_IN' => ['category' => 'TRANSFER', 'label' => 'Masuk Transit'],
        'TRANSIT_OUT' => ['category' => 'TRANSFER', 'label' => 'Keluar Transit'],
        'BIN_TRANSFER_IN' => ['category' => 'TRANSFER', 'label' => 'Transfer'],
        'BIN_TRANSFER_OUT' => ['category' => 'TRANSFER', 'label' => 'Transfer'],
        'BIN_TRANSFER_REVERSAL' => ['category' => 'TRANSFER', 'label' => 'Koreksi Pindah Bin'],
        'BIN_TRANSFER_REVERT_OUT' => ['category' => 'TRANSFER', 'label' => 'Koreksi Pindah Bin'],
        'TRANSIT_REVERT_IN' => ['category' => 'TRANSFER', 'label' => 'Koreksi Transit'],
        'PUTAWAY_IN' => ['category' => 'TAGIHAN', 'label' => 'Tagihan'],
        'PUTAWAY_OUT' => ['category' => 'TAGIHAN', 'label' => 'Tagihan'],
        'PUTAWAY_REVERSAL' => ['category' => 'TAGIHAN', 'label' => 'Koreksi Tagihan'],
        'SPLIT_IN' => ['category' => 'TRANSFER', 'label' => 'Pecah Stok'],
        'SPLIT_OUT' => ['category' => 'TRANSFER', 'label' => 'Pecah Stok'],
        'TRANSFER_REJECT_RETURN' => ['category' => 'TRANSFER', 'label' => 'Retur Tolak Transfer'],

        'ORDER_RESTORE' => ['category' => 'PESANAN_BATAL', 'label' => 'Pesanan Batal'],
        'ORDER_RESTORE_CANCEL' => ['category' => 'PESANAN_BATAL', 'label' => 'Pesanan Batal'],
        'ORDER_CANCELLED' => ['category' => 'PESANAN_BATAL', 'label' => 'Pesanan Batal'],

        'RESERVE' => ['category' => 'CADANGAN', 'label' => 'Cadangan'],
        'RESERVE_CANCEL' => ['category' => 'CADANGAN', 'label' => 'Tahanan Dibatalkan'],
        'RESERVE_EXPIRED' => ['category' => 'CADANGAN', 'label' => 'Tahanan Kedaluwarsa'],
    ];

    private const CATEGORY_LABELS = [
        'TAGIHAN' => 'Tagihan',
        'PENYESUAIAN' => 'Penyesuaian',
        'RETUR_PEMBELIAN' => 'Retur Pembelian',
        'RETUR_PENJUALAN' => 'Retur Penjualan',
        'FAKTUR' => 'Faktur',
        'PESANAN' => 'Pesanan',
        'TRANSFER' => 'Transfer',
        'PESANAN_BATAL' => 'Pesanan Batal',
        'CADANGAN' => 'Cadangan',
    ];

    private const CATEGORY_ORDER = [
        'TAGIHAN',
        'PENYESUAIAN',
        'RETUR_PEMBELIAN',
        'RETUR_PENJUALAN',
        'FAKTUR',
        'PESANAN',
        'TRANSFER',
        'PESANAN_BATAL',
        'CADANGAN',
    ];

    public const INVOICE_SOURCES = ['INVOICE', 'ORDER_PICK', 'ORDER_SHIP', 'ORDER_COMPLETE_OUT'];

    public const REVERSAL_SOURCES = [
        'PUTAWAY_REVERSAL',
        'BIN_TRANSFER_REVERSAL',
        'TRANSFER_REVERT',
        'BIN_TRANSFER_REVERT_OUT',
        'TRANSIT_REVERT_IN',
        'PURCHASE_REVERSAL',
        'PICKING_REVERSAL',
        'ORDER_COMPLETE_REVERSAL',
    ];

    public const UNRECORDED_REVERSAL_SOURCES = [
        'PUTAWAY_REVERSAL',
        'BIN_TRANSFER_REVERSAL',
        'TRANSFER_REVERT',
    ];

    /**
     * Automatic cancellation/revert sources whose exact net-zero pairs are
     * hidden from the operational chronology while retained in the ledger.
     */
    public const CHRONOLOGY_NETTABLE_REVERSAL_SOURCES = [
        ...self::UNRECORDED_REVERSAL_SOURCES,
        'BIN_TRANSFER_REVERT_OUT',
        'TRANSIT_REVERT_IN',
    ];

    public const ALLOCATION_PARTITION_SOURCES = [
        'ORDER_RESERVE',
        'ORDER_RELEASE',
        'ORDER',
        'ORDER_CANCELLED',
        'RESERVE',
        'RESERVE_CANCEL',
        'RESERVE_EXPIRED',
    ];

    public const ORDER_DEDUCT_SOURCES = [
        'ORDER_RESERVE',
        'ORDER',
        'RESERVE',
    ];

    public const ORDER_RESTORE_SOURCES = [
        'ORDER_RELEASE',
        'ORDER_RESTORE',
        'ORDER_RESTORE_CANCEL',
        'ORDER_CANCELLED',
        'RESERVE_CANCEL',
        'RESERVE_EXPIRED',
    ];

    public const ORDER_LEDGER_SOURCES = ['ORDER_RESERVE', 'ORDER_RELEASE'];

    public const NON_PHYSICAL_SOURCES = [
        'ORDER_RESERVE',
        'ORDER_RELEASE',
        'ORDER',
        'ORDER_CANCELLED',
        'RESERVE',
        'RESERVE_CANCEL',
        'RESERVE_EXPIRED',
        'ORDER_SHIP',
    ];

    public const CLEAN_HIDDEN_SOURCES = [
        'INVOICE',
        'ORDER_PICK',
        'ORDER_SHIP',
        ...self::NON_PHYSICAL_SOURCES,
        ...self::HIDDEN_SOURCES,
    ];

    public const CLEAN_PHYSICAL_TRANSFER_SOURCES = [
        'TRANSFER_IN',
        'TRANSFER_OUT',
        'TRANSFER_REVERT',
        'TRANSIT_IN',
        'TRANSIT_OUT',
        'BIN_TRANSFER_IN',
        'BIN_TRANSFER_OUT',
        'BIN_TRANSFER_REVERSAL',
        'BIN_TRANSFER_REVERT_OUT',
        'TRANSIT_REVERT_IN',
        'TRANSFER_REJECT_RETURN',
        'SPLIT_IN',
        'SPLIT_OUT',
    ];

    public const CLEAN_HISTORICAL_PICKING_SOURCES = [
        'ORDER_COMPLETE_OUT',
        'ORDER_COMPLETE_REVERSAL',
    ];

    public const TRANSIT_SOURCES = ['TRANSIT_IN', 'TRANSIT_OUT'];

    public const DRILL_SCOPES = [
        'transit' => self::TRANSIT_SOURCES,
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

            $upper = strtoupper($token);

            if ($upper === 'ORDER') {
                $sources = array_merge($sources, ['ORDER_RESERVE', 'RESERVE']);

                continue;
            }

            $matchedCategory = false;
            foreach (self::SOURCES as $source => $meta) {
                if ($meta['category'] === $upper) {
                    $sources[] = $source;
                    $matchedCategory = true;
                }
            }

            if (! $matchedCategory) {
                if (array_key_exists($upper, self::SOURCES)) {
                    $sources[] = $upper;
                } else {
                    $sources[] = $token;
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
