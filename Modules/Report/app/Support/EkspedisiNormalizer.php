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

    public static function family(?string $provider, ?string $resi = null, ?string $shipmentType = null): string
    {
        $hay = strtoupper(trim((string) $provider));
        $type = strtoupper(trim((string) $shipmentType));

        if (in_array($type, ['INSTANT', 'SAME_DAY'], true)) {
            return 'Instan/Sameday';
        }

        if ($hay !== '') {
            foreach (self::FAMILIES as $family => $needles) {
                foreach ($needles as $needle) {
                    if (str_contains($hay, $needle)) {
                        return $family;
                    }
                }
            }
        }

        if ($resi) {
            $guessed = self::guessFromResi($resi);
            if ($guessed) {
                return $guessed;
            }
        }

        return trim((string) $provider) ?: self::LAINNYA;
    }

    private static function guessFromResi(?string $resi): ?string
    {
        $r = strtoupper(trim((string) $resi));
        if ($r === '') return null;

        if (str_starts_with($r, 'SPX')) return 'SPX';
        if (str_starts_with($r, 'JP') || str_starts_with($r, 'JX') || str_starts_with($r, 'JZ') || str_starts_with($r, 'JT') || str_starts_with($r, 'JO')) return 'J&T';
        if (str_starts_with($r, 'LX') || str_starts_with($r, 'LZ')) return 'Lazada';
        if (str_starts_with($r, 'GK')) return 'GTL';
        if (str_starts_with($r, 'ID') || str_starts_with($r, 'IDE')) return 'ID Express';
        if (str_starts_with($r, 'NL')) return 'Ninja';

        if (str_starts_with($r, '00') && is_numeric($r) && strlen($r) >= 10) return 'SiCepat';

        if (str_starts_with($r, '100') && is_numeric($r) && strlen($r) >= 10) return 'AnterAja';

        if (is_numeric($r) && strlen($r) === 15) return 'JNE';

        return null;
    }
}
