<?php

namespace Modules\Warehouse\Services;

use Illuminate\Support\Collection;
use Modules\Warehouse\Models\BinMultiSkuRule;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;

class BinMultiSkuRuleService
{
    public function findLocation(string $locationId): ?Location
    {
        return Location::find($locationId);
    }

    /**
     * Daftar aturan multi-SKU sebuah lokasi, tiap baris dilengkapi jumlah rak
     * yang cocok dengan polanya (`matched_count`).
     */
    public function rulesWithMatchCount(string $locationId): Collection
    {
        return BinMultiSkuRule::where('location_id', $locationId)
            ->orderBy('pattern')
            ->get()
            ->each(function (BinMultiSkuRule $rule) use ($locationId) {
                $rule->matched_count = $this->countMatching($locationId, $rule->pattern);
            });
    }

    public function findRule(string $locationId, string $ruleId): ?BinMultiSkuRule
    {
        return BinMultiSkuRule::where('location_id', $locationId)->find($ruleId);
    }

    /**
     * @throws \Illuminate\Database\QueryException Pola sudah terdaftar (unik per lokasi).
     */
    public function createRule(string $locationId, array $data): BinMultiSkuRule
    {
        $data['pattern'] = trim($data['pattern']);
        $data['location_id'] = $locationId;

        $rule = BinMultiSkuRule::create($data);
        $rule->matched_count = $this->countMatching($locationId, $rule->pattern);

        return $rule;
    }

    /**
     * @throws \Illuminate\Database\QueryException Pola sudah terdaftar (unik per lokasi).
     */
    public function updateRule(BinMultiSkuRule $rule, array $data): BinMultiSkuRule
    {
        if (array_key_exists('pattern', $data)) {
            $data['pattern'] = trim($data['pattern']);
        }

        $rule->update($data);
        $rule->matched_count = $this->countMatching((string) $rule->location_id, $rule->pattern);

        return $rule;
    }

    public function deleteRule(BinMultiSkuRule $rule): void
    {
        $rule->delete();
    }

    protected const MAX_DEEP_SUGGESTION_BINS = 50;

    protected const MAX_SUGGESTIONS = 500;

    protected const SAMPLES_PER_SUGGESTION = 3;

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

    public function suggestedPatterns(string $locationId): array
    {
        $counts = [];
        $samples = [];

        foreach (LocationBin::where('location_id', $locationId)->orderBy('bin_final_code')->pluck('bin_final_code') as $code) {
            $code = (string) $code;
            $parts = explode('-', $code);
            $prefix = '';

            for ($i = 0, $last = count($parts) - 1; $i < $last; $i++) {
                $prefix = $prefix === '' ? $parts[$i] : $prefix . '-' . $parts[$i];

                if ($prefix === '') {
                    continue;
                }

                $pattern = $prefix . '-*';
                $counts[$pattern] = ($counts[$pattern] ?? 0) + 1;

                if (count($samples[$pattern] ?? []) < self::SAMPLES_PER_SUGGESTION) {
                    $samples[$pattern][] = $code;
                }
            }
        }

        $suggestions = [];

        foreach ($counts as $pattern => $count) {
            if (substr_count($pattern, '-') > 1) {
                if ($count > self::MAX_DEEP_SUGGESTION_BINS) {
                    continue;
                }

                $parent = $this->parentPattern($pattern);
                if ($parent !== null && ($counts[$parent] ?? null) === $count) {
                    continue;
                }
            }

            $suggestions[] = [
                'pattern' => $pattern,
                'matched_count' => $count,
                'samples' => $samples[$pattern] ?? [],
            ];
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

    protected function toRegex(string $pattern): string
    {
        return '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';
    }

    protected function toSqlLike(string $pattern): string
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $pattern);

        return str_replace('*', '%', $escaped);
    }
}
