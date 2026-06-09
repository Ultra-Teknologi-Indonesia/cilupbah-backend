<?php

namespace Modules\Product\Support;

use Illuminate\Support\Str;

/**
 * Port 1:1 dari helper grouping cilupbah-ops
 * (apps/api/src/trpc/routers/product.router.ts).
 *
 * Inti fitur: kelompokkan produk berdasarkan kode awal SKU sampai tanda "-"
 * pertama (mis. "SLR-GREEN-IP14" -> "SLR").
 */
class SkuGrouping
{
    /**
     * Type code = segmen pertama SKU sebelum "-", di-uppercase.
     * Return null kalau panjang < 2 supaya tidak nge-bucket noise.
     *
     *   "SLR-GREEN-IP14" -> "SLR"
     *   "ABC"            -> "ABC"
     *   "A-B"            -> null
     *   ""               -> null
     */
    public static function skuTypeCode(?string $sku): ?string
    {
        $sku = trim((string) $sku);
        if ($sku === '') {
            return null;
        }

        $idx = strpos($sku, '-');
        $code = strtoupper($idx === false ? $sku : substr($sku, 0, $idx));

        return strlen($code) >= 2 ? $code : null;
    }

    /**
     * Normalisasi nama untuk perbandingan case/whitespace/diakritik-insensitive.
     * Dipakai sebagai KEY grouping katalog + dedup nama.
     *
     * Faithful port dari cilupbah-ops:
     *   name.normalize('NFD').replace(/[̀-ͯ]/g,'').trim().toLowerCase().replace(/\s+/g,' ')
     *
     * PENTING: hanya membuang combining diacritical marks (U+0300–U+036F) — karakter
     * non-latin (CJK, Cyrillic, dll) DIPERTAHANKAN. Tidak boleh pakai Str::ascii()
     * karena itu mentransliterasi/membuang non-latin → bisa mengolaps banyak produk
     * berbeda ke satu key kosong.
     */
    public static function normalizeName(?string $name): string
    {
        $s = (string) $name;

        if (class_exists(\Normalizer::class)) {
            $s = \Normalizer::normalize($s, \Normalizer::FORM_D) ?: $s;
            $s = preg_replace('/[\x{0300}-\x{036f}]/u', '', $s);
        } else {
            // Fallback (intl tidak tersedia): minimal buang diakritik latin.
            $s = (string) Str::of($s)->ascii();
        }

        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/\s+/u', ' ', trim($s));

        return $s;
    }

    /**
     * Signature nama = N kata pertama, lowercase, buang device tail
     * ("for iphone/hp/samsung/..."), tanda kurung, dan setelah "/".
     * Dipakai khusus tab Rekomendasi.
     */
    public static function nameSignature(?string $name, int $maxWords = 3): string
    {
        $lower = (string) Str::of((string) $name)->lower()->squish();

        $cut = preg_replace(
            '/\s+(for|untuk)\s+(iphone|iph\*ne|ip\*ne|ph\*ne|hp\s|samsung|xiaomi|redmi|oppo|vivo|realme).*$/i',
            '',
            $lower
        );
        $cut = preg_replace('/\s*\(.*$/', '', $cut);
        $cut = preg_replace('/\s+\/.*$/', '', $cut);
        $cut = preg_replace('/[^\w\s]/u', ' ', $cut);
        $cut = (string) Str::of($cut)->squish();

        $tokens = array_values(array_filter(
            $cut === '' ? [] : explode(' ', $cut),
            fn ($w) => strlen($w) > 1 && ! preg_match('/^\d+$/', $w)
        ));

        return implode(' ', array_slice($tokens, 0, $maxWords));
    }

    /**
     * Untuk tiap group key, resolve master yang sudah ada dari merge existing.
     * Tie-break: jumlah pemakaian desc, lalu panjang master desc.
     *
     * @param  array<int,array{id:string,name:string,sku:?string}>  $products
     * @param  array<string,string>  $mergeMap  product_id => master_name
     * @param  callable(array):?string  $keyFn
     * @return array<string,string>  key => master_name
     */
    public static function resolveMasterPerKey(array $products, array $mergeMap, callable $keyFn): array
    {
        $counts = []; // key => [master_name => count]
        foreach ($products as $p) {
            $master = $mergeMap[$p['id']] ?? null;
            if ($master === null) {
                continue;
            }
            $key = $keyFn($p);
            if ($key === null) {
                continue;
            }
            $counts[$key][$master] = ($counts[$key][$master] ?? 0) + 1;
        }

        $out = [];
        foreach ($counts as $key => $masters) {
            $top = null;
            $topCount = -1;
            foreach ($masters as $master => $c) {
                if ($c > $topCount || ($c === $topCount && strlen((string) $master) > strlen((string) $top))) {
                    $top = (string) $master;
                    $topCount = $c;
                }
            }
            $out[$key] = $top;
        }

        return $out;
    }
}
