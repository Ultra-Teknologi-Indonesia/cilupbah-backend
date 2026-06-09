<?php

namespace Modules\Product\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductMerge;
use Modules\Product\Models\ProductMergeHidden;
use Modules\Product\Repositories\ProductMergeRepository;
use Modules\Product\Support\SkuGrouping;
use Ramsey\Uuid\Uuid;

/**
 * Port fitur Merge & Auto-Merge produk dari cilupbah-ops
 * (apps/api/src/trpc/routers/product.router.ts) ke cilupbah-be.
 *
 * Overlay non-destruktif: produk & varian sumber tidak diubah, hanya 2 tabel
 * kecil (product_merges, product_merge_hidden). Merge bekerja lintas store &
 * channel — produk dari toko/marketplace berbeda bisa digabung ke 1 master.
 */
class ProductMergeService
{
    /** Default pattern grup by-nama (selain grup by kode SKU). */
    private const DEFAULT_NAME_PATTERN_GROUPS = [
        ['premium full cover', 'premium silikon full', 'premium silicone full'],
        ['matte soft case'],
    ];

    public function __construct(private ProductMergeRepository $repo) {}

    // ──────────────────────────────────────────────────────────────
    // Katalog
    // ──────────────────────────────────────────────────────────────

    /**
     * Katalog produk ter-group (master + solo) — lintas store/channel.
     *
     * @return array{rows:array,total:int,counts:array{all:int,merged:int,unmerged:int,hidden:int},page:int,limit:int}
     */
    public function catalog(string $filter = 'all', string $q = '', int $page = 1, int $limit = 50): array
    {
        $products = $this->repo->masterProducts();
        $mergeMap = $this->repo->mergeMap();
        $hiddenSet = array_flip($this->repo->hiddenNames());

        /** @var array<string,array> $groups */
        $groups = [];
        foreach ($products as $p) {
            $master = $mergeMap[$p->id] ?? null;
            $effName = $master ?? $p->name;
            $key = SkuGrouping::normalizeName($effName);

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'name' => $effName,
                    'norm_key' => $key,
                    'merged' => $master !== null,
                    'hidden' => isset($hiddenSet[$effName]),
                    'foto' => null,
                    'vendor' => $p->brand->name ?? '',
                    'category' => $p->category->name ?? '',
                    'product_count' => 0,
                    'sku_count' => 0,
                    'products' => [],
                    'skus' => [],      // set: sku => true
                    'channels' => [],  // set: channel_shop_id => detail
                ];
            }

            $g = &$groups[$key];
            $g['product_count']++;
            if (! $g['foto']) {
                $foto = $this->primaryImage($p);
                if ($foto) {
                    $g['foto'] = $foto;
                }
            }
            if (! $g['vendor'] && $p->brand) {
                $g['vendor'] = $p->brand->name;
            }
            if (! $g['category'] && $p->category) {
                $g['category'] = $p->category->name;
            }
            if ($master !== null) {
                $g['merged'] = true;
            }

            $variantSkus = $p->variants->pluck('sku')->filter()->values()->all();
            foreach ($variantSkus as $s) {
                $g['skus'][$s] = true;
            }
            $g['sku_count'] += count($variantSkus);

            $g['products'][] = [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $this->representativeSku($p),
                'variant_skus' => $variantSkus,
                'merged' => $master !== null,
            ];

            // Cakupan multi-store / multi-channel
            foreach ($p->channelMappings as $cm) {
                $shop = $cm->channelShop;
                if (! $shop) {
                    continue;
                }
                $g['channels'][$cm->channel_shop_id] = [
                    'channel_shop_id' => $cm->channel_shop_id,
                    'shop_name' => $shop->shop_name,
                    'channel_name' => $shop->channel->name ?? null,
                    'channel_code' => $shop->channel->code ?? null,
                ];
            }
            unset($g);
        }

        // Counts (independen dari search supaya badge tidak loncat saat user ngetik)
        $counts = [
            'all' => 0,
            'merged' => 0,
            'unmerged' => 0,
            'hidden' => 0,
        ];
        foreach ($groups as $g) {
            if ($g['hidden']) {
                $counts['hidden']++;
                continue;
            }
            $counts['all']++;
            if ($g['merged']) {
                $counts['merged']++;
            } else {
                $counts['unmerged']++;
            }
        }

        // Finalize set -> list
        $out = [];
        foreach ($groups as $g) {
            $g['skus'] = array_keys($g['skus']);
            $g['channels'] = array_values($g['channels']);
            $g['channel_count'] = count($g['channels']);
            $out[] = $g;
        }

        // Filter
        $out = array_values(array_filter($out, function ($g) use ($filter) {
            return match ($filter) {
                'hidden' => $g['hidden'],
                'merged' => $g['merged'] && ! $g['hidden'],
                'unmerged' => ! $g['merged'] && ! $g['hidden'],
                default => ! $g['hidden'],
            };
        }));

        // Search (case/diakritik-insensitive; termasuk SKU anggota)
        $needle = SkuGrouping::normalizeName($q);
        if ($needle !== '') {
            $out = array_values(array_filter($out, function ($g) use ($needle) {
                if (str_contains($g['norm_key'], $needle)) {
                    return true;
                }
                if (str_contains(SkuGrouping::normalizeName($g['vendor']), $needle)) {
                    return true;
                }
                if (str_contains(SkuGrouping::normalizeName($g['category']), $needle)) {
                    return true;
                }
                foreach ($g['skus'] as $s) {
                    if (str_contains(SkuGrouping::normalizeName($s), $needle)) {
                        return true;
                    }
                }

                return false;
            }));
        }

        // Sort: merged dulu (by sku_count desc, lalu nama), baru solo (alfabet)
        usort($out, function ($a, $b) {
            if ($a['merged'] !== $b['merged']) {
                return $a['merged'] ? -1 : 1;
            }
            if ($a['merged']) {
                return ($b['sku_count'] <=> $a['sku_count']) ?: strcmp($a['name'], $b['name']);
            }

            return strcmp($a['name'], $b['name']);
        });

        $total = count($out);
        $paged = array_slice($out, ($page - 1) * $limit, $limit);

        return [
            'rows' => $paged,
            'total' => $total,
            'counts' => $counts,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // Rekomendasi
    // ──────────────────────────────────────────────────────────────

    public function suggestions(string $q = ''): array
    {
        $products = $this->repo->masterProducts();
        $mergeMap = $this->repo->mergeMap();

        $rows = $products->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'sku' => $this->representativeSku($p),
            'vendor' => $p->brand->name ?? null,
        ])->all();

        $keyFn = function (array $p): ?string {
            $sig = SkuGrouping::nameSignature($p['name']);
            $code = SkuGrouping::skuTypeCode($p['sku']);
            if ($sig === '' || ! $code) {
                return null;
            }

            return "{$sig}|{$code}";
        };

        $existingMasterByKey = SkuGrouping::resolveMasterPerKey($rows, $mergeMap, $keyFn);

        $byKey = [];
        foreach ($rows as $p) {
            if (isset($mergeMap[$p['id']])) {
                continue;
            }
            $key = $keyFn($p);
            if (! $key) {
                continue;
            }
            $byKey[$key][] = $p;
        }

        $out = [];
        foreach ($byKey as $key => $group) {
            $existing = $existingMasterByKey[$key] ?? null;
            if (! $existing && count($group) < 2) {
                continue;
            }
            $rawSet = array_unique(array_map(fn ($p) => $p['name'], $group));
            if (! $existing && count($rawSet) === 1) {
                continue; // sudah natural grouped
            }

            $master = $existing ?? $this->pickMasterName($group);
            $normSet = array_unique(array_map(fn ($p) => SkuGrouping::normalizeName($p['name']), $group));
            [$sig, $code] = explode('|', $key);

            $out[] = [
                'prefix' => "{$sig} · {$code}",
                'suggested_master_name' => $master,
                'existing_master' => (bool) $existing,
                'unique_name_count' => count($normSet),
                'total' => count($group),
                'products' => array_map(fn ($p) => [
                    'id' => $p['id'],
                    'name' => $p['name'],
                    'sku' => $p['sku'],
                    'vendor' => $p['vendor'],
                ], $group),
            ];
        }

        usort($out, function ($a, $b) {
            return (((int) $b['existing_master']) <=> ((int) $a['existing_master']))
                ?: ($b['total'] <=> $a['total'])
                ?: ($a['unique_name_count'] <=> $b['unique_name_count'])
                ?: strcmp($a['suggested_master_name'], $b['suggested_master_name']);
        });

        $needle = SkuGrouping::normalizeName($q);
        if ($needle !== '') {
            $out = array_values(array_filter($out, function ($s) use ($needle) {
                if (str_contains(SkuGrouping::normalizeName($s['prefix']), $needle)) {
                    return true;
                }
                if (str_contains(SkuGrouping::normalizeName($s['suggested_master_name']), $needle)) {
                    return true;
                }
                foreach ($s['products'] as $p) {
                    if (str_contains(SkuGrouping::normalizeName((string) $p['sku']), $needle)) {
                        return true;
                    }
                }

                return false;
            }));
        }

        return $out;
    }

    // ──────────────────────────────────────────────────────────────
    // Daftar merge (tab "Sudah Di-merge")
    // ──────────────────────────────────────────────────────────────

    public function listMerges(string $q = ''): array
    {
        $merges = $this->repo->mergesWithProducts();

        $grouped = [];
        foreach ($merges as $m) {
            $p = $m->product;
            if (! $p) {
                continue;
            }
            $name = $m->master_name;
            if (! isset($grouped[$name])) {
                $grouped[$name] = [
                    'master_name' => $name,
                    'product_count' => 0,
                    'products' => [],
                    'channels' => [], // set
                ];
            }
            $grouped[$name]['product_count']++;
            $grouped[$name]['products'][] = [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $this->representativeSku($p),
                'variant_skus' => $p->variants->pluck('sku')->filter()->values()->all(),
                'updated_at' => $m->updated_at,
            ];
            foreach ($p->channelMappings as $cm) {
                $shop = $cm->channelShop;
                if (! $shop) {
                    continue;
                }
                $grouped[$name]['channels'][$cm->channel_shop_id] = [
                    'channel_shop_id' => $cm->channel_shop_id,
                    'shop_name' => $shop->shop_name,
                    'channel_name' => $shop->channel->name ?? null,
                    'channel_code' => $shop->channel->code ?? null,
                ];
            }
        }

        $out = [];
        foreach ($grouped as $g) {
            $g['channels'] = array_values($g['channels']);
            $g['channel_count'] = count($g['channels']);
            $out[] = $g;
        }

        $needle = SkuGrouping::normalizeName($q);
        if ($needle !== '') {
            $out = array_values(array_filter($out, function ($g) use ($needle) {
                if (str_contains(SkuGrouping::normalizeName($g['master_name']), $needle)) {
                    return true;
                }
                foreach ($g['products'] as $p) {
                    if (str_contains(SkuGrouping::normalizeName($p['name']), $needle)) {
                        return true;
                    }
                    foreach ($p['variant_skus'] as $s) {
                        if (str_contains(SkuGrouping::normalizeName((string) $s), $needle)) {
                            return true;
                        }
                    }
                }

                return false;
            }));
        }

        return $out;
    }

    // ──────────────────────────────────────────────────────────────
    // Auto-merge (inti: by kode awal SKU sampai "-" pertama)
    // ──────────────────────────────────────────────────────────────

    /**
     * @param  array<int,array<int,string>>|null  $namePatternGroups
     * @return array{merged:int,groups_affected:int}
     */
    public function autoMergeAll(?array $namePatternGroups = null): array
    {
        $patternGroups = array_map(
            fn ($arr) => array_map(fn ($p) => strtolower(trim((string) $p)), $arr),
            $namePatternGroups ?? self::DEFAULT_NAME_PATTERN_GROUPS
        );

        $products = $this->repo->masterProducts();
        $mergeMap = $this->repo->mergeMap();

        $rows = $products->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'sku' => $this->representativeSku($p),
        ])->all();

        $groupKey = function (array $p) use ($patternGroups): ?string {
            $lower = strtolower(trim((string) $p['name']));
            foreach ($patternGroups as $i => $pats) {
                foreach ($pats as $pat) {
                    if ($pat !== '' && str_starts_with($lower, $pat)) {
                        return "name:group{$i}";
                    }
                }
            }
            $code = SkuGrouping::skuTypeCode($p['sku']);

            return $code ? "code:{$code}" : null;
        };

        $existingMasterByKey = SkuGrouping::resolveMasterPerKey($rows, $mergeMap, $groupKey);

        $byKey = [];
        $groupAllSize = [];
        foreach ($rows as $p) {
            $key = $groupKey($p);
            if (! $key) {
                continue;
            }
            $groupAllSize[$key] = ($groupAllSize[$key] ?? 0) + 1;
            if (isset($mergeMap[$p['id']])) {
                continue; // sudah merged → master existing tidak ditimpa
            }
            $byKey[$key][] = $p;
        }

        $toApply = [];
        foreach ($byKey as $key => $solo) {
            $existing = $existingMasterByKey[$key] ?? null;
            $totalSize = $groupAllSize[$key] ?? count($solo);
            if (! $existing && $totalSize < 2) {
                continue;
            }
            $master = $existing ?? $this->pickLongestName($solo);
            foreach ($solo as $p) {
                $toApply[] = ['product_id' => $p['id'], 'master_name' => $master];
            }
        }

        if (empty($toApply)) {
            return ['merged' => 0, 'groups_affected' => 0];
        }

        $now = now();
        $insertRows = array_map(fn ($r) => [
            'id' => Uuid::uuid7()->toString(),
            'product_id' => $r['product_id'],
            'master_name' => $r['master_name'],
            'created_at' => $now,
            'updated_at' => $now,
        ], $toApply);

        // product_id unique → insertOrIgnore skip duplikat (idempoten)
        $merged = ProductMerge::query()->insertOrIgnore($insertRows);

        return ['merged' => $merged, 'groups_affected' => count($byKey)];
    }

    // ──────────────────────────────────────────────────────────────
    // Apply / bulk merge
    // ──────────────────────────────────────────────────────────────

    /**
     * @param  array<int,string>  $productIds
     * @return array{merged:int,master_name:string}
     */
    public function applyMerge(string $masterName, array $productIds): array
    {
        $masterName = trim($masterName);
        if ($masterName === '') {
            throw new DomainException('Master name tidak boleh kosong');
        }
        $productIds = array_values(array_unique($productIds));

        $found = Product::query()->whereIn('id', $productIds)->pluck('id')->all();
        $missing = array_values(array_diff($productIds, $found));
        if (! empty($missing)) {
            throw new DomainException(
                'Produk tidak ditemukan: '.implode(', ', array_slice($missing, 0, 5)).(count($missing) > 5 ? '...' : '')
            );
        }

        DB::transaction(fn () => $this->applyMergeToProducts($productIds, $masterName));

        return ['merged' => count($productIds), 'master_name' => $masterName];
    }

    /**
     * Merge berdasarkan nama produk (case/diakritik-insensitive) → 1 master.
     * Mengumpulkan produk yang sudah ter-merge ke nama itu + produk solo yang namanya cocok.
     *
     * @param  array<int,string>  $productNames
     * @return array{merged:int,master_name:string}
     */
    public function bulkMergeProducts(string $masterName, array $productNames): array
    {
        $masterName = trim($masterName);
        if ($masterName === '') {
            throw new DomainException('Master name tidak boleh kosong');
        }

        $targetNormKeys = array_flip(array_map(
            fn ($n) => SkuGrouping::normalizeName($n),
            $productNames
        ));

        $mergedIds = ProductMerge::query()
            ->whereIn('master_name', $productNames)
            ->pluck('product_id')
            ->all();

        $nameIds = Product::query()
            ->where('status', Product::STATUS_MASTER)
            ->get(['id', 'name'])
            ->filter(fn ($p) => isset($targetNormKeys[SkuGrouping::normalizeName($p->name)]))
            ->pluck('id')
            ->all();

        $allIds = array_values(array_unique(array_merge($mergedIds, $nameIds)));
        if (empty($allIds)) {
            throw new DomainException('Tidak ada produk yang ditemukan untuk produk yang dipilih');
        }

        DB::transaction(fn () => $this->applyMergeToProducts($allIds, $masterName));

        return ['merged' => count($allIds), 'master_name' => $masterName];
    }

    // ──────────────────────────────────────────────────────────────
    // Unmerge
    // ──────────────────────────────────────────────────────────────

    public function unmerge(string $productId): array
    {
        ProductMerge::query()->where('product_id', $productId)->delete();

        return ['ok' => true];
    }

    public function unmergeMaster(string $masterName): array
    {
        return DB::transaction(function () use ($masterName) {
            $this->cascadeHiddenOnUnmerge([$masterName]);
            $removed = ProductMerge::query()->where('master_name', $masterName)->delete();

            return ['removed' => $removed];
        });
    }

    /** @param array<int,string> $masterNames */
    public function bulkUnmergeMasters(array $masterNames): array
    {
        return DB::transaction(function () use ($masterNames) {
            $this->cascadeHiddenOnUnmerge($masterNames);
            $removed = ProductMerge::query()->whereIn('master_name', $masterNames)->delete();

            return ['removed' => $removed, 'masters' => count($masterNames)];
        });
    }

    // ──────────────────────────────────────────────────────────────
    // Hide / Unhide
    // ──────────────────────────────────────────────────────────────

    /** @param array<int,string> $masterNames */
    public function bulkHide(array $masterNames): array
    {
        $now = now();
        $rows = array_map(fn ($n) => [
            'id' => Uuid::uuid7()->toString(),
            'master_name' => $n,
            'created_at' => $now,
            'updated_at' => $now,
        ], array_values(array_unique($masterNames)));

        $hidden = ProductMergeHidden::query()->insertOrIgnore($rows);

        return ['hidden' => $hidden, 'total' => count($masterNames)];
    }

    /** @param array<int,string> $masterNames */
    public function bulkUnhide(array $masterNames): array
    {
        $unhidden = ProductMergeHidden::query()->whereIn('master_name', $masterNames)->delete();

        return ['unhidden' => $unhidden];
    }

    // ──────────────────────────────────────────────────────────────
    // Internal
    // ──────────────────────────────────────────────────────────────

    /**
     * Apply merge atomik + cascade hidden flag.
     * Snapshot nama efektif lama → kalau ada yang hidden, master baru inherit.
     *
     * @param  array<int,string>  $productIds
     */
    private function applyMergeToProducts(array $productIds, string $masterName): void
    {
        $currentMerges = ProductMerge::query()
            ->whereIn('product_id', $productIds)
            ->pluck('master_name', 'product_id')
            ->all();
        $names = Product::query()
            ->whereIn('id', $productIds)
            ->pluck('name', 'id')
            ->all();

        $oldEff = [];
        foreach ($productIds as $id) {
            $eff = $currentMerges[$id] ?? ($names[$id] ?? null);
            if ($eff !== null) {
                $oldEff[$eff] = true;
            }
        }
        $oldEffNames = array_keys($oldEff);

        $wasHidden = ! empty($oldEffNames)
            && ProductMergeHidden::query()->whereIn('master_name', $oldEffNames)->exists();

        // Pindahkan SKU: hapus baris lama, insert baru
        ProductMerge::query()->whereIn('product_id', $productIds)->delete();
        $now = now();
        ProductMerge::query()->insert(array_map(fn ($id) => [
            'id' => Uuid::uuid7()->toString(),
            'product_id' => $id,
            'master_name' => $masterName,
            'created_at' => $now,
            'updated_at' => $now,
        ], $productIds));

        // Cascade: bersihkan hidden lama (kecuali master baru), set hidden master baru kalau perlu
        if (! empty($oldEffNames)) {
            ProductMergeHidden::query()
                ->whereIn('master_name', $oldEffNames)
                ->where('master_name', '!=', $masterName)
                ->delete();
        }
        if ($wasHidden) {
            ProductMergeHidden::query()->firstOrCreate(['master_name' => $masterName]);
        }
    }

    /**
     * Saat master di-unmerge: kalau master hidden, turunkan flag ke nama produk
     * asli tiap anggota, lalu hapus baris hidden master.
     *
     * @param  array<int,string>  $masterNames
     */
    private function cascadeHiddenOnUnmerge(array $masterNames): void
    {
        if (empty($masterNames)) {
            return;
        }
        $hiddenSet = array_flip(
            ProductMergeHidden::query()->whereIn('master_name', $masterNames)->pluck('master_name')->all()
        );
        if (empty($hiddenSet)) {
            return;
        }

        $merges = ProductMerge::query()
            ->whereIn('master_name', array_keys($hiddenSet))
            ->get(['product_id', 'master_name']);
        $names = Product::query()
            ->whereIn('id', $merges->pluck('product_id')->all())
            ->pluck('name', 'id')
            ->all();

        $toHide = [];
        foreach ($merges as $m) {
            if (isset($hiddenSet[$m->master_name])) {
                $n = $names[$m->product_id] ?? null;
                if ($n !== null) {
                    $toHide[$n] = true;
                }
            }
        }
        foreach (array_keys($toHide) as $n) {
            ProductMergeHidden::query()->firstOrCreate(['master_name' => $n]);
        }
        ProductMergeHidden::query()->whereIn('master_name', array_keys($hiddenSet))->delete();
    }

    /** SKU representatif: varian pertama ber-SKU (item_code), fallback ke products.sku. */
    private function representativeSku(Product $p): ?string
    {
        if ($p->relationLoaded('variants')) {
            $v = $p->variants->first(fn ($x) => filled($x->sku));
            if ($v) {
                return $v->sku;
            }
        }

        return $p->sku;
    }

    private function primaryImage(Product $p): ?string
    {
        if (! $p->relationLoaded('media')) {
            return null;
        }
        $primary = $p->media->firstWhere('is_primary', true) ?? $p->media->first();

        return $primary->url ?? null;
    }

    /** Master = nama paling sering; tie-break panjang desc. */
    private function pickMasterName(array $group): string
    {
        $counts = [];
        foreach ($group as $p) {
            $counts[$p['name']] = ($counts[$p['name']] ?? 0) + 1;
        }
        $best = null;
        $bestCount = -1;
        foreach ($counts as $n => $c) {
            if ($c > $bestCount || ($c === $bestCount && strlen((string) $n) > strlen((string) $best))) {
                $best = (string) $n;
                $bestCount = $c;
            }
        }

        return (string) $best;
    }

    /** Master = nama unik terpanjang dalam grup. */
    private function pickLongestName(array $group): string
    {
        $uniq = array_values(array_unique(array_map(fn ($p) => $p['name'], $group)));
        usort($uniq, fn ($a, $b) => strlen((string) $b) - strlen((string) $a));

        return (string) $uniq[0];
    }
}
