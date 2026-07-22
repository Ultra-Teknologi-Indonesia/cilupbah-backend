<?php

namespace Modules\Report\Support;

/**
 * Menyederhanakan nama layanan kurir dari channel ("SPX Hemat",
 * "J&T Express Standard", "GoTo Logistics GTL NEXT-DAY DELIVERY") menjadi
 * keluarga ekspedisi ("SPX", "J&T", "GTL") untuk pengelompokan laporan.
 *
 * Sistem tidak menyimpan keluarga ekspedisi sebagai kolom — master kurir hanya
 * berisi 141 nama layanan lengkap tanpa kode keluarga. Jadi pemetaan ini
 * berbasis kata kunci, dan sengaja dikumpulkan di satu tempat supaya mudah
 * dikoreksi kalau ada layanan baru yang belum tertangkap.
 */
final class EkspedisiNormalizer
{
    /**
     * Kurir bernama diperiksa lebih dulu, "Instan" jadi jaring terakhir. Dengan
     * begitu "SPX Instant" tetap masuk keluarga SPX (layanan instan milik SPX),
     * sejalan dengan carve-out di BulkShippingLabelService; sedangkan GoSend,
     * Grab, dan Lalamove — yang tak memuat nama kurir mana pun — jatuh ke Instan.
     *
     * @var array<string, array<int, string>>
     */
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

        // Provider tak dikenal ditampilkan apa adanya (dipangkas), bukan disatukan
        // ke "Lainnya" — supaya kurir baru tetap terbaca dan bisa ditambahkan ke peta.
        return trim((string) $provider) ?: self::LAINNYA;
    }
}
