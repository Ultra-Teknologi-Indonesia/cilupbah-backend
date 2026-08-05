<?php

namespace Modules\Channel\Support;

use Modules\Channel\Exceptions\ShopeeApiException;
use Modules\Channel\Exceptions\TokenExpiredException;

class UploadErrorPresenter
{
    public const TOKEN = 'token';
    public const RETRYABLE = 'retryable';
    public const USER_FIXABLE = 'user_fixable';
    public const FATAL = 'fatal';

    protected const KEYWORDS = [
        [['kurir', 'logistik', 'pengiriman', 'sls'], 'Pengaturan pengiriman bermasalah', 'Aktifkan minimal satu kurir dan pastikan berat serta dimensi produk terisi, lalu upload ulang.'],
        [['gambar', 'foto', 'image'], 'Gambar produk bermasalah', 'Tambahkan atau ganti gambar produk ke format JPG/PNG, lalu upload ulang.'],
        [['kategori'], 'Kategori belum sesuai', 'Atur kategori channel untuk produk ini, lalu upload ulang.'],
        [['atribut'], 'Atribut produk belum lengkap', 'Lengkapi atribut wajib sesuai kategori channel, lalu upload ulang.'],
        [['varian', 'variasi', 'model'], 'Varian produk bermasalah', 'Periksa jumlah dan isian varian produk, lalu upload ulang.'],
        [['berat'], 'Berat produk tidak valid', 'Isi berat produk lebih dari 0 pada master data produk.'],
        [['dimensi', 'panjang', 'lebar', 'tinggi'], 'Dimensi produk tidak valid', 'Isi panjang, lebar, dan tinggi produk pada master data produk.'],
        [['harga', 'grosir'], 'Harga produk tidak sesuai', 'Perbaiki harga produk sesuai ketentuan channel.'],
        [['merek', 'brand'], 'Merek produk bermasalah', 'Pilih merek yang sesuai untuk kategori ini.'],
        [['deskripsi'], 'Deskripsi produk bermasalah', 'Perbaiki deskripsi produk sesuai ketentuan channel.'],
        [['nama produk'], 'Nama produk bermasalah', 'Perbaiki nama produk sesuai ketentuan channel.'],
        [['stok', 'gudang'], 'Stok atau gudang tidak sesuai', 'Periksa pengaturan gudang dan stok produk.'],
    ];

    public static function fromThrowable(string $channelCode, \Throwable $e): array
    {
        if ($e instanceof TokenExpiredException) {
            return self::build(self::TOKEN, $channelCode, $e->getMessage(), null);
        }

        if ($e instanceof ShopeeApiException) {
            $detail = $e->errorInfo ?: $e->rawMessage;

            return self::build($e->category, $channelCode, $e->getMessage(), $detail);
        }

        $message = $e->getMessage();

        if ($channelCode === 'lazada' && str_contains($message, 'Lazada API Error')) {
            $resolved = LazadaErrorCatalog::resolve($message);

            return self::build($resolved['category'], $channelCode, $resolved['message'], $resolved['detail']);
        }

        return self::fromMessage($channelCode, $message);
    }

    public static function fromMessage(string $channelCode, string $message): array
    {
        if ($channelCode === 'lazada' && str_contains($message, 'Lazada API Error')) {
            $resolved = LazadaErrorCatalog::resolve($message);

            return self::build($resolved['category'], $channelCode, $resolved['message'], $resolved['detail']);
        }

        $category = self::guessCategory($channelCode, $message);

        return self::build($category, $channelCode, $message, null);
    }

    protected static function build(string $category, string $channelCode, string $reason, ?string $detail): array
    {
        $reason = trim($reason) !== '' ? trim($reason) : 'Upload gagal tanpa keterangan.';
        [$title, $action] = self::titleAndAction($category, $reason);

        return [
            'category' => $category,
            'title' => $title,
            'reason' => $reason,
            'action' => $action,
            'detail' => $detail !== null && trim($detail) !== '' && trim($detail) !== $reason ? trim($detail) : null,
            'retryable' => $category === self::RETRYABLE || $category === self::TOKEN,
        ];
    }

    protected static function titleAndAction(string $category, string $reason): array
    {
        if ($category === self::TOKEN) {
            return ['Koneksi channel terputus', 'Hubungkan ulang toko di menu Integrasi Channel, lalu upload ulang.'];
        }

        if ($category === self::RETRYABLE) {
            return ['Channel sedang bermasalah', 'Coba upload ulang beberapa saat lagi.'];
        }

        $lower = mb_strtolower($reason);
        foreach (self::KEYWORDS as [$keywords, $title, $action]) {
            foreach ($keywords as $keyword) {
                if (str_contains($lower, $keyword)) {
                    return [$title, $action];
                }
            }
        }

        if ($category === self::USER_FIXABLE) {
            return ['Produk perlu diperbaiki', 'Periksa data produk sesuai keterangan, lalu upload ulang.'];
        }

        return ['Produk ditolak channel', 'Periksa detail teknis atau hubungi dukungan channel.'];
    }

    protected static function guessCategory(string $channelCode, string $message): string
    {
        $lower = mb_strtolower($message);

        if (str_contains($lower, 'token') || str_contains($lower, 'reauth') || str_contains($lower, 'hubungkan ulang')) {
            return self::TOKEN;
        }

        $retryable = ['coba lagi', 'coba upload ulang', 'sedang sibuk', 'sedang bermasalah', 'timeout', 'time out', 'rate limit', 'frequency exceeds', 'call limit', 'sedang lambat', 'http error [5'];
        foreach ($retryable as $needle) {
            if (str_contains($lower, $needle)) {
                return self::RETRYABLE;
            }
        }

        $userFixable = ['wajib', 'tidak sesuai', 'tidak valid', 'melebihi batas', 'minimal', 'maksimal', 'belum lengkap', 'kurang dari', 'terlalu', 'diperlukan', 'gambar', 'kategori', 'atribut', 'varian', 'berat', 'dimensi', 'harga', 'merek', 'deskripsi'];
        foreach ($userFixable as $needle) {
            if (str_contains($lower, $needle)) {
                return self::USER_FIXABLE;
            }
        }

        return self::FATAL;
    }
}
