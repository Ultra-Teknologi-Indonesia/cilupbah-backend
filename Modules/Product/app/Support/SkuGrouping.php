<?php

namespace Modules\Product\Support;

use Illuminate\Support\Str;

class SkuGrouping
{

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

    public static function normalizeName(?string $name): string
    {
        $s = (string) $name;

        if (class_exists(\Normalizer::class)) {
            $s = \Normalizer::normalize($s, \Normalizer::FORM_D) ?: $s;
            $s = preg_replace('/[\x{0300}-\x{036f}]/u', '', $s);
        } else {

            $s = (string) Str::of($s)->ascii();
        }

        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/\s+/u', ' ', trim($s));

        return $s;
    }

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

    public static function resolveMasterPerKey(array $products, array $mergeMap, callable $keyFn): array
    {
        $counts = []; 
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
