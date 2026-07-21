<?php

namespace Modules\Report\Support;

final class OrderPerformanceSpec
{
    public const PICKER = 'picker';
    public const PACKER = 'packer';
    public const SHIPPER = 'shipper';
    public const KURIR = 'kurir';
    public const PESANAN = 'pesanan';

    public const TYPES = [self::PICKER, self::PACKER, self::SHIPPER, self::KURIR, self::PESANAN];

    public const SUMMARY_TYPES = [self::PICKER, self::PACKER, self::SHIPPER, self::KURIR];

    public static function supportsSummary(string $type): bool
    {
        return in_array($type, self::SUMMARY_TYPES, true);
    }

    public static function title(string $type, bool $detail): string
    {

        $base = $type === self::PESANAN
            ? 'Laporan Pemrosesan Pesanan'
            : 'Laporan Performa ' . ucfirst($type === self::KURIR ? 'Kurir' : $type);

        return $detail && $type !== self::PESANAN ? $base . ' - Detail' : $base;
    }

    public static function detailColumns(string $type): array
    {
        $tanggal = ['key' => 'tanggal', 'label' => 'Tanggal Transaksi'];
        $noTrx = ['key' => 'no_transaksi', 'label' => 'No Transaksi'];
        $durasi = ['key' => 'durasi', 'label' => 'Durasi', 'align' => 'center'];

        return match ($type) {
            self::PICKER => [
                $tanggal, $noTrx,
                ['key' => 'qty', 'label' => 'Qty', 'align' => 'right'],
                $durasi,
            ],
            self::PACKER => [
                $tanggal, $noTrx,
                ['key' => 'no_pesanan', 'label' => 'No Pesanan'],
                ['key' => 'no_resi', 'label' => 'No Resi'],
                ['key' => 'sku', 'label' => 'SKU'],
                ['key' => 'qty', 'label' => 'Qty', 'align' => 'right'],
                $durasi,
            ],
            self::SHIPPER, self::KURIR => [
                $tanggal, $noTrx,
                ['key' => 'qty', 'label' => 'Quantity', 'align' => 'right'],
                $durasi,
            ],
            self::PESANAN => [
                $tanggal, $noTrx,
                ['key' => 'durasi_proses', 'label' => 'Durasi Proses', 'align' => 'center'],
                ['key' => 'durasi_penugasan_pick', 'label' => 'Durasi Penugasan Pick', 'align' => 'center'],
                ['key' => 'durasi_pick', 'label' => 'Durasi Pick', 'align' => 'center'],
                ['key' => 'durasi_pack', 'label' => 'Durasi Pack', 'align' => 'center'],
                ['key' => 'durasi_ship', 'label' => 'Durasi Ship', 'align' => 'center'],
                ['key' => 'durasi_selesai', 'label' => 'Durasi Pesanan Selesai', 'align' => 'center'],
            ],
        };
    }

    public static function secondaryGroupLabel(string $type): ?string
    {
        return match ($type) {
            self::KURIR => 'Kurir',
            self::PESANAN => null,
            default => 'Nama Pengguna',
        };
    }

    public static function summaryFirstColumnLabel(string $type): string
    {
        return $type === self::KURIR ? 'Lokasi' : 'Nama Pengguna';
    }

    public static function summaryGroupLabel(string $type): string
    {
        return $type === self::KURIR ? 'Kurir' : 'Lokasi Gudang';
    }
}
