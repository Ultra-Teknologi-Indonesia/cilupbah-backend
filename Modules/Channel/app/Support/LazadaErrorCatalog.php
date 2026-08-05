<?php

namespace Modules\Channel\Support;

class LazadaErrorCatalog
{
    public const TOKEN = 'token';
    public const RETRYABLE = 'retryable';
    public const USER_FIXABLE = 'user_fixable';
    public const FATAL = 'fatal';

    protected const SUBSTRINGS = [
        ['access frequency', self::RETRYABLE, 'Terlalu banyak permintaan ke Lazada. Sistem akan mencoba lagi otomatis.'],
        ['call limit', self::RETRYABLE, 'Batas frekuensi Lazada tercapai. Coba lagi beberapa saat lagi.'],
        ['system error', self::RETRYABLE, 'Lazada sedang bermasalah. Coba upload ulang beberapa saat lagi.'],
        ['timeout', self::RETRYABLE, 'Lazada tidak merespons tepat waktu. Coba upload ulang.'],
        ['token', self::TOKEN, 'Koneksi ke Lazada terputus. Hubungkan ulang toko lalu upload ulang.'],
        ['image', self::USER_FIXABLE, 'Gambar produk tidak sesuai. Gunakan gambar JPG/PNG lalu upload ulang.'],
        ['category', self::USER_FIXABLE, 'Kategori produk belum sesuai. Atur kategori channel lalu upload ulang.'],
        ['attribute', self::USER_FIXABLE, 'Atribut produk wajib belum lengkap. Lengkapi atribut sesuai kategori.'],
        ['brand', self::USER_FIXABLE, 'Merek produk tidak sesuai. Pilih merek yang tepat untuk kategori ini.'],
        ['price', self::USER_FIXABLE, 'Harga produk tidak sesuai ketentuan Lazada.'],
        ['weight', self::USER_FIXABLE, 'Berat produk tidak valid. Isi berat lebih dari 0.'],
        ['duplicate', self::USER_FIXABLE, 'Produk serupa sudah ada di Lazada.'],
    ];

    public static function resolve(string $rawMessage): array
    {
        $message = trim(preg_replace('/^Lazada API Error:\s*/', '', $rawMessage) ?? $rawMessage);
        $lower = mb_strtolower($message);

        foreach (self::SUBSTRINGS as [$needle, $category, $friendly]) {
            if (str_contains($lower, $needle)) {
                return ['category' => $category, 'message' => $friendly, 'detail' => $message];
            }
        }

        if (str_starts_with($message, 'E5') || str_contains($lower, 'create product failed') || str_contains($lower, 'update product failed')) {
            return [
                'category' => self::USER_FIXABLE,
                'message' => 'Lazada menolak produk ini. Umumnya karena kategori, atribut wajib, gambar, atau merek belum sesuai.',
                'detail' => $message,
            ];
        }

        return [
            'category' => self::FATAL,
            'message' => 'Permintaan ditolak Lazada: ' . $message,
            'detail' => $message,
        ];
    }
}
