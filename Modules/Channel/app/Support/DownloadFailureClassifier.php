<?php

namespace Modules\Channel\Support;

use Illuminate\Support\Str;

class DownloadFailureClassifier
{
    public static function classify(?string $message): string
    {
        $message = trim((string) $message);

        if ($message === '') {
            return 'Tidak diketahui';
        }

        $m = mb_strtolower($message);

        return match (true) {
            str_contains($m, 'products_sku_unique'), str_contains($m, 'product_variants_sku_unique')
                => 'SKU duplikat atau kosong (produk tanpa SKU bertabrakan)',
            str_contains($m, 'duplicate key'), str_contains($m, '23505')
                => 'Data duplikat (melanggar batasan unik)',
            str_contains($m, '23502'), str_contains($m, 'not null')
                => 'Kolom wajib kosong',
            str_contains($m, '23503'), str_contains($m, 'foreign key')
                => 'Relasi tidak valid (kategori/akun)',
            str_contains($m, 'kategori'), str_contains($m, 'category'), str_contains($m, 'atribut'), str_contains($m, 'attribute')
                => 'Kategori atau atribut tidak valid',
            str_contains($m, 'timeout'), str_contains($m, 'timed out'), str_contains($m, 'curl')
                => 'Channel lambat atau timeout',
            str_contains($m, 'rate limit'), str_contains($m, 'too many'), str_contains($m, '429')
                => 'Rate-limit channel',
            str_contains($m, 'memory') && str_contains($m, 'exhausted')
                => 'Proses kehabisan memori',
            str_contains($m, 'sqlstate')
                => 'Kesalahan database',
            default => Str::limit($message, 80),
        };
    }
}
