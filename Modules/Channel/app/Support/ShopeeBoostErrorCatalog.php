<?php

namespace Modules\Channel\Support;

use Modules\Channel\Exceptions\ShopeeApiException;
use Modules\Channel\Exceptions\TokenExpiredException;

final class ShopeeBoostErrorCatalog
{
    public const INVALID_ITEM_ID = 'ID produk Shopee tidak valid. Sinkronkan produk terlebih dahulu lalu coba lagi.';

    public const MISSING_RESULT = 'Shopee tidak mengembalikan hasil untuk produk ini. Coba lagi nanti.';

    public const GENERIC_FAILURE = 'Shopee menolak produk ini untuk dinaikkan. Periksa status produk di Shopee lalu coba lagi.';

    public const GENERIC_API_FAILURE = 'Shopee tidak dapat memproses kenaikan produk. Coba lagi nanti.';

    public const TOKEN_FAILURE = 'Koneksi ke Shopee terputus. Hubungkan ulang toko lalu coba lagi.';

    public static function failureReason(?string $reason): string
    {
        $value = mb_strtolower(trim((string) $reason));
        $value = (string) preg_replace('/[._-]+/', ' ', $value);

        if ($value === '') {
            return self::GENERIC_FAILURE;
        }

        if (str_contains($value, 'repeated') || str_contains($value, 'again too soon')) {
            return 'Produk baru saja dinaikkan dan belum bisa dinaikkan lagi. Coba lagi setelah masa jeda Shopee berakhir.';
        }

        if (str_contains($value, 'not found') || str_contains($value, 'does not exist')) {
            return 'Produk tidak ditemukan di Shopee. Sinkronkan produk lalu coba lagi.';
        }

        if (str_contains($value, 'not belong') || str_contains($value, 'different shop')) {
            return 'Produk bukan milik toko Shopee yang terhubung.';
        }

        if (str_contains($value, 'deleted') || str_contains($value, 'removed')) {
            return 'Produk sudah dihapus di Shopee. Sinkronkan produk terlebih dahulu.';
        }

        if (str_contains($value, 'invalid') || str_contains($value, 'illegal')) {
            return 'Data produk tidak valid di Shopee. Perbarui data produk lalu coba lagi.';
        }

        if (str_contains($value, 'unlisted') || str_contains($value, 'not on sale') || str_contains($value, 'not active')) {
            return 'Produk belum aktif di Shopee. Aktifkan produk di Shopee lalu coba lagi.';
        }

        if (str_contains($value, 'promotion') || str_contains($value, 'flash sale')) {
            return 'Produk sedang mengikuti promosi Shopee dan belum dapat dinaikkan.';
        }

        if (str_contains($value, 'banned') || str_contains($value, 'penalty') || str_contains($value, 'penalized')) {
            return 'Produk atau toko sedang dibatasi oleh Shopee sehingga tidak dapat dinaikkan.';
        }

        if (str_contains($value, 'permission') || str_contains($value, 'unauthorized') || str_contains($value, 'not allowed')) {
            return 'Toko tidak memiliki izin untuk menaikkan produk ini di Shopee.';
        }

        if (str_contains($value, 'busy') || str_contains($value, 'timeout') || str_contains($value, 'try later')) {
            return 'Shopee sedang sibuk. Coba lagi beberapa saat lagi.';
        }

        return self::GENERIC_FAILURE;
    }

    public static function exceptionMessage(\Throwable $exception): string
    {
        if ($exception instanceof TokenExpiredException) {
            return self::TOKEN_FAILURE;
        }

        if ($exception instanceof ShopeeApiException) {
            $resolved = ShopeeErrorCatalog::resolve(
                $exception->errorCode,
                $exception->rawMessage,
                $exception->errorInfo,
            );

            return self::knownApiMessage($resolved['code'], $resolved['message']);
        }

        return self::GENERIC_API_FAILURE;
    }

    private static function knownApiMessage(string $code, string $message): string
    {
        if (in_array($code, ['error_param', 'error_param_validate', 'error_invalid_item_list'], true)) {
            return 'Data produk untuk dinaikkan tidak sesuai. Sinkronkan produk lalu coba lagi.';
        }

        if ($code === 'error_auth') {
            return 'Koneksi atau izin toko Shopee tidak valid. Hubungkan ulang toko lalu coba lagi.';
        }

        if ($code === 'error_server') {
            return 'Shopee sedang bermasalah. Coba lagi nanti.';
        }

        $knownCodes = [
            'error_boost_item_failed',
            'error_boost_item_all_failed',
            'error_network',
            'error_system_busy',
            'error_data',
            'error_shop',
            'error_shop_not_found',
            'error_auth_shop_not_found',
            'error_param_shop_id_not_found',
            'error_item_not_found',
            'error_item_or_variation_not_found',
            'error_invalid_item_list',
            'error_get_parnter_token_failed',
            'error_inner',
        ];

        if (in_array($code, $knownCodes, true)) {
            return $message;
        }

        return self::GENERIC_API_FAILURE;
    }
}
