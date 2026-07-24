<?php

namespace Modules\Warehouse\Services;

use Modules\Warehouse\Models\BinMultiSkuRule;
use Modules\Warehouse\Models\LocationBin;

/**
 * Menjawab satu pertanyaan: kode rak ini boleh diisi lebih dari satu SKU atau tidak.
 *
 * Jawabannya diturunkan dari pola kode rak di `bin_multi_sku_rules`, bukan dari kolom
 * di `location_bins`. Kode rak adalah sumber kebenaran, jadi tidak ada salinan yang
 * bisa melenceng dan tidak ada langkah "terapkan ke rak existing".
 */
class BinMultiSkuRuleService
{
    protected const MAX_SUGGESTIONS = 50;

    /** @var array<string, string[]> locationId => daftar pola aktif */
    protected static array $patternCache = [];

    public function allowsMultiSku(?LocationBin $bin): bool
    {
        if (! $bin) {
            return false;
        }

        return $this->allowsMultiSkuCode(
            (string) $bin->location_id,
            (string) $bin->bin_final_code
        );
    }

    public function allowsMultiSkuCode(string $locationId, ?string $binFinalCode): bool
    {
        if ($locationId === '' || $binFinalCode === null || $binFinalCode === '') {
            return false;
        }

        foreach ($this->activePatterns($locationId) as $pattern) {
            if (preg_match($this->toRegex($pattern), $binFinalCode) === 1) {
                return true;
            }
        }

        return false;
    }

    public function countMatching(string $locationId, string $pattern): int
    {
        return $this->matchingQuery($locationId, $pattern)->count();
    }

    /** @return string[] */
    public function sampleMatching(string $locationId, string $pattern, int $limit = 3): array
    {
        return $this->matchingQuery($locationId, $pattern)
            ->orderBy('bin_final_code')
            ->limit($limit)
            ->pluck('bin_final_code')
            ->all();
    }

    public function totalBins(string $locationId): int
    {
        return LocationBin::where('location_id', $locationId)->count();
    }

    /**
     * Daftar pola yang bisa dipilih, diturunkan dari kode rak yang benar-benar ada.
     *
     * Dipakai supaya pola tidak perlu diketik: tidak bisa salah ketik, tidak bisa
     * menghasilkan pola yang cocok nol rak, dan jumlah rak sudah terlihat sebelum dipilih.
     *
     * HANYA segmen pertama (setara zona) yang ditawarkan — `O-*`, `GK-*`, `IN-*`.
     * Prefix lebih dalam sengaja tidak ditawarkan: pada 10.696 rak WH-KECIL ia
     * menghasilkan ratusan opsi (`O-A1-K1-*`, `GK-15-*`, …) yang menenggelamkan tiga
     * pilihan yang benar-benar dipakai.
     *
     * Konsekuensinya, rak khusus yang berbagi segmen pertama dengan kelompok besar
     * tidak bisa ditandai lewat UI — mis. `O-LX-KX-KANTOR` yang berprefix `O` sama
     * dengan 10.457 rak shelving. Rak seperti itu perlu kode rak berawalan tersendiri.
     * BE tetap menerima pola sedalam apa pun, jadi kalau nanti dibutuhkan cukup
     * longgarkan daftar ini tanpa mengubah pencocokannya.
     *
     * @return array<int, array{pattern: string, matched_count: int}>
     */
    public function suggestedPatterns(string $locationId): array
    {
        $counts = [];

        foreach (LocationBin::where('location_id', $locationId)->pluck('bin_final_code') as $code) {
            $parts = explode('-', (string) $code);

            if (count($parts) < 2 || $parts[0] === '') {
                continue;
            }

            $pattern = $parts[0] . '-*';
            $counts[$pattern] = ($counts[$pattern] ?? 0) + 1;
        }

        $suggestions = [];
        foreach ($counts as $pattern => $count) {
            $suggestions[] = ['pattern' => $pattern, 'matched_count' => $count];
        }

        usort($suggestions, fn ($a, $b) => $b['matched_count'] <=> $a['matched_count']
            ?: strcmp($a['pattern'], $b['pattern']));

        return array_slice($suggestions, 0, self::MAX_SUGGESTIONS);
    }

    public static function flushPatternCache(): void
    {
        static::$patternCache = [];
    }

    protected function matchingQuery(string $locationId, string $pattern)
    {
        return LocationBin::where('location_id', $locationId)
            ->whereRaw('bin_final_code ILIKE ?', [$this->toSqlLike($pattern)]);
    }

    /** @return string[] */
    protected function activePatterns(string $locationId): array
    {
        if (! array_key_exists($locationId, static::$patternCache)) {
            static::$patternCache[$locationId] = BinMultiSkuRule::query()
                ->where('location_id', $locationId)
                ->where('is_active', true)
                ->pluck('pattern')
                ->all();
        }

        return static::$patternCache[$locationId];
    }

    /**
     * `*` adalah satu-satunya wildcard. Sengaja BUKAN fnmatch(): fnmatch juga
     * memperlakukan `?` dan `[...]` sebagai wildcard, sedangkan di sisi SQL keduanya
     * literal — pencocokan in-memory dan hitungan di layar akan berbeda diam-diam.
     */
    protected function toRegex(string $pattern): string
    {
        return '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';
    }

    /**
     * PostgreSQL: `ILIKE` (bukan `LIKE`, yang case-sensitive di Postgres).
     * `%` dan `_` di dalam pola diperlakukan literal, jadi harus di-escape lebih dulu —
     * `_` adalah wildcard satu-karakter di LIKE/ILIKE.
     */
    protected function toSqlLike(string $pattern): string
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $pattern);

        return str_replace('*', '%', $escaped);
    }
}
