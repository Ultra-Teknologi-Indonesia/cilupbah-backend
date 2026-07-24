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
    protected const MAX_DEEP_SUGGESTION_BINS = 50;

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
     * Segmen pertama (setara zona) selalu ditawarkan. Prefix yang lebih dalam hanya
     * ditawarkan kalau kelompoknya kecil DAN benar-benar mempersempit induknya — kalau
     * `O-LX-KX-*` mengenai rak yang sama persis dengan `O-LX-*`, yang ditawarkan cukup
     * yang lebih pendek.
     *
     * @return array<int, array{pattern: string, matched_count: int}>
     */
    public function suggestedPatterns(string $locationId): array
    {
        $counts = [];

        foreach (LocationBin::where('location_id', $locationId)->pluck('bin_final_code') as $code) {
            $parts = explode('-', (string) $code);
            $prefix = '';

            for ($i = 0, $last = count($parts) - 1; $i < $last; $i++) {
                $prefix = $prefix === '' ? $parts[$i] : $prefix . '-' . $parts[$i];
                $pattern = $prefix . '-*';
                $counts[$pattern] = ($counts[$pattern] ?? 0) + 1;
            }
        }

        $suggestions = [];

        foreach ($counts as $pattern => $count) {
            $depth = substr_count($pattern, '-');

            if ($depth > 1) {
                if ($count > self::MAX_DEEP_SUGGESTION_BINS) {
                    continue;
                }

                $parent = $this->parentPattern($pattern);
                if ($parent !== null && ($counts[$parent] ?? null) === $count) {
                    continue;
                }
            }

            $suggestions[] = ['pattern' => $pattern, 'matched_count' => $count];
        }

        usort($suggestions, fn ($a, $b) => $b['matched_count'] <=> $a['matched_count']
            ?: strcmp($a['pattern'], $b['pattern']));

        return array_slice($suggestions, 0, self::MAX_SUGGESTIONS);
    }

    protected function parentPattern(string $pattern): ?string
    {
        $parts = explode('-', substr($pattern, 0, -2));

        if (count($parts) < 2) {
            return null;
        }

        array_pop($parts);

        return implode('-', $parts) . '-*';
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
