<?php

namespace Modules\Report\Support;

final class EkspedisiNormalizer
{

    private const FAMILIES = [
        'SPX'    => ['SPX', 'SHOPEE EXPRESS', 'SHOPEE XPRESS'],
        'J&T'    => ['J&T', 'JNT', 'J AND T'],
        'JNE'    => ['JNE'],
        'GTL'    => ['GTL', 'GOTO LOGISTICS', 'GOTO KILAT'],
        'Lazada' => ['LAZADA', 'LEX', 'LGS'],
        'AnterAja' => ['ANTERAJA', 'ANTER AJA'],
        'SiCepat'  => ['SICEPAT', 'SI CEPAT'],
        'Ninja'    => ['NINJA'],
        'ID Express' => ['ID EXPRESS', 'IDEXPRESS'],
        'Pos'      => ['POS INDONESIA', 'POS REGULER', 'POS KILAT'],
        'Wahana'   => ['WAHANA'],
        'Instan' => ['INSTANT', 'SAME DAY', 'SAMEDAY', 'GOSEND', 'GO-SEND', 'GOJEK', 'GRAB', 'LALAMOVE', 'BORZO', 'DELIVEREE'],
    ];

    private const LAINNYA = 'Lainnya';

    public static function family(?string $provider): string
    {
        $hay = strtoupper(trim((string) $provider));
        if ($hay === '') {
            return self::LAINNYA;
        }

        foreach (self::FAMILIES as $family => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($hay, $needle)) {
                    return $family;
                }
            }
        }

        return trim((string) $provider) ?: self::LAINNYA;
    }
}
