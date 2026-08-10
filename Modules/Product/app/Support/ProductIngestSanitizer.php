<?php

namespace Modules\Product\Support;

use Illuminate\Support\Str;

class ProductIngestSanitizer
{
    private const NAME_MAX = 255;
    private const SKU_MAX = 255;
    private const BARCODE_MAX = 255;

    public static function sanitize(array $data): array
    {
        if (isset($data['name'])) {
            $data['name'] = Str::limit(trim((string) $data['name']), self::NAME_MAX, '');
        }

        $data['sku'] = self::code($data['sku'] ?? null, self::SKU_MAX);

        if (isset($data['media'])) {
            $data['media'] = self::media($data['media']);
        }

        if (isset($data['variants']) && is_array($data['variants'])) {
            $data['variants'] = array_map(function ($variant) {
                if (is_array($variant)) {
                    if (array_key_exists('sku', $variant)) {
                        $variant['sku'] = self::code($variant['sku'], self::SKU_MAX);
                    }
                    if (array_key_exists('barcode', $variant)) {
                        $variant['barcode'] = self::code($variant['barcode'], self::BARCODE_MAX);
                    }
                    if (isset($variant['media'])) {
                        $variant['media'] = self::media($variant['media']);
                    }
                }

                return $variant;
            }, $data['variants']);
        }

        return $data;
    }

    private static function code($value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return Str::limit($value, $max, '');
    }

    private static function media($list): array
    {
        if (! is_array($list)) {
            return [];
        }

        return array_values(array_filter($list, function ($item) {
            $url = is_array($item) ? ($item['url'] ?? null) : null;

            return is_string($url) && preg_match('#^https?://#i', $url) === 1;
        }));
    }
}
