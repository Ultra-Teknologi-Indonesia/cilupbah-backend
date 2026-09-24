<?php

declare(strict_types=1);

namespace Modules\Report\Support;

use Modules\Report\Services\ExportManager;

final class ExportCatalog
{
    private const LABELS = [
        'negative-stock' => 'Laporan Stok Minus',
        'transfer' => 'Transfer Masuk/Keluar',
        'order-performance' => 'Performa Proses Pesanan',
        'putaway-performance' => 'Performa Proses Penempatan',
        'putaway-list' => 'Penempatan Barang',
        'shipment-by-courier' => 'Pengiriman per Ekspedisi',
        'picklist-detail-photo' => 'Detail Picklist dengan Foto',
        'picklist-detail' => 'Detail Picklist',
        'picklist-list' => 'Daftar Picklist',
        'picklist-pdf' => 'Picklist',
        'inventory-stock' => 'Persediaan Barang',
        'inventory-rack' => 'Persediaan per Rak',
        'shipment-list' => 'Daftar Pengiriman/Manifest',
        'monitor-stock-xlsx' => 'Monitor Stok',
        'monitor-stock-pdf' => 'Monitor Stok',
        'stock-position-csv' => 'Posisi Stok',
        'stock-position-pdf' => 'Posisi Stok',
        'product-catalog-csv' => 'Katalog Produk',
        'sales-list' => 'Penjualan/Daftar Pesanan',
        'sales-product' => 'Penjualan per Produk/SKU',
        'sales-return' => 'Retur Penjualan',
        'sales-income' => 'Rincian Pendapatan',
        'customer-list' => 'Daftar Pelanggan',
        'sales-orders' => 'Daftar Pesanan',
        'cancelled-orders' => 'Pesanan Dibatalkan',
        'sales-return-detail' => 'Laporan Retur Detail',
        'settlement' => 'Laporan Settlement',
        'purchase-order-list' => 'Daftar Pesanan Pembelian',
        'purchase-order-detail' => 'Rincian Pesanan Pembelian',
        'rack-allocation' => 'Alokasi Rak',
        'stock-adjustment' => 'Penyesuaian Stok',
        'stock-adjustment-report' => 'Laporan Penyesuaian Stok',
        'penyesuaian-stok-pdf' => 'Penyesuaian Stok',
    ];

    private const CATEGORY_BY_TYPE = [
        'negative-stock' => 'inventory',
        'inventory-stock' => 'inventory',
        'inventory-rack' => 'inventory',
        'monitor-stock-xlsx' => 'inventory',
        'monitor-stock-pdf' => 'inventory',
        'stock-position-csv' => 'inventory',
        'stock-position-pdf' => 'inventory',
        'rack-allocation' => 'inventory',
        'stock-adjustment' => 'inventory',
        'stock-adjustment-report' => 'inventory',
        'penyesuaian-stok-pdf' => 'inventory',
        'transfer' => 'warehouse',
        'picklist-detail-photo' => 'warehouse',
        'picklist-detail' => 'warehouse',
        'picklist-list' => 'warehouse',
        'picklist-pdf' => 'warehouse',
        'shipment-list' => 'warehouse',
        'order-performance' => 'warehouse',
        'putaway-performance' => 'warehouse',
        'putaway-list' => 'warehouse',
        'shipment-by-courier' => 'warehouse',
        'transfer-out-bulk-pdf' => 'warehouse',
        'putaway-bulk-pdf' => 'warehouse',
        'stock-adjustment-bulk-pdf' => 'warehouse',
        'picklist-bulk-pdf' => 'warehouse',
        'manifest-bulk-pdf' => 'warehouse',
        'invoice-bulk-pdf' => 'warehouse',
        'order-performance-pdf' => 'warehouse',
        'putaway-performance-pdf' => 'warehouse',
        'putaway-list-pdf' => 'warehouse',
        'shipment-by-courier-pdf' => 'warehouse',
        'sales-list' => 'sales',
        'sales-product' => 'sales',
        'sales-return' => 'sales',
        'sales-income' => 'sales',
        'customer-list' => 'sales',
        'sales-orders' => 'sales',
        'cancelled-orders' => 'sales',
        'sales-return-detail' => 'sales',
        'settlement' => 'sales',
        'purchase-order-list' => 'purchase',
        'purchase-order-detail' => 'purchase',
        'product-catalog-csv' => 'other',
    ];

    public static function types(): array
    {
        return ExportManager::TYPES;
    }

    public static function label(string $type): string
    {
        if (isset(ExportManager::TABULAR_PDF_TYPES[$type])) {
            return self::label(ExportManager::TABULAR_PDF_TYPES[$type]).' (PDF)';
        }

        if (str_ends_with($type, '-pdf')) {
            $sourceType = substr($type, 0, -4);
            if (in_array($sourceType, self::types(), true)) {
                return self::label($sourceType).' (PDF)';
            }
        }

        return self::LABELS[$type] ?? str($type)->replace('-', ' ')->title()->toString();
    }

    public static function category(string $type): string
    {
        if (isset(self::CATEGORY_BY_TYPE[$type])) {
            return self::CATEGORY_BY_TYPE[$type];
        }

        if (str_ends_with($type, '-pdf')) {
            $sourceType = substr($type, 0, -4);
            if (isset(self::CATEGORY_BY_TYPE[$sourceType])) {
                return self::CATEGORY_BY_TYPE[$sourceType];
            }
        }

        return 'other';
    }

    public static function format(string $type): string
    {
        if (str_ends_with($type, '-pdf') || in_array($type, ExportManager::PDF_TYPES, true)) {
            return 'pdf';
        }

        if (in_array($type, ['product-catalog-csv', 'stock-position-csv', 'purchase-order-list', 'purchase-order-detail'], true)) {
            return 'csv';
        }

        return 'xlsx';
    }

    public static function typesForCategory(string $category): array
    {
        return array_values(array_filter(
            self::types(),
            static fn (string $type): bool => self::category($type) === $category,
        ));
    }

    public static function typesForFormat(string $format): array
    {
        return array_values(array_filter(
            self::types(),
            static fn (string $type): bool => self::format($type) === $format,
        ));
    }

    public static function typesMatchingSearch(string $search): array
    {
        $needle = mb_strtolower(trim($search));

        return array_values(array_filter(
            self::types(),
            static fn (string $type): bool => str_starts_with(mb_strtolower($type), $needle)
                || str_starts_with(mb_strtolower(self::label($type)), $needle),
        ));
    }
}
