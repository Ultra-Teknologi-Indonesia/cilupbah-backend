<?php

namespace Modules\Product\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Impor pemetaan kategori internal -> kategori channel dari dump gaya Jubelio.
 *
 * Sumber cocok berdasarkan NAMA/PATH kategori (`full_category_name`), BUKAN id
 * Jubelio, sehingga file yang sama bisa dipakai di staging maupun production
 * walau id internalnya berbeda.
 *
 * Untuk tiap baris & channel yang ada di tabel `channels`:
 *   1) upsert `channel_categories` (channel_id + external_id marketplace + name),
 *   2) isi `category_channel_mappings` (category_id internal -> channel_category uuid).
 *
 * Idempoten (upsert + cek-exists, tanpa TRUNCATE). Default fill-gap: tidak
 * menimpa pemetaan channel yang sudah ada; pakai --overwrite untuk memaksa.
 * Selalu jalankan --dry-run dulu untuk melihat ringkasan (cocok/skip per env).
 */
class ImportCategoryChannelMappings extends Command
{
    protected $signature = 'channels:import-category-mappings
        {path? : Path file JSON dump (default: Modules/Product/database/data/jubelio_category_mappings.json)}
        {--channels=shopee,lazada,tiktok : Channel yang diproses, dipisah koma (shopee,lazada,tiktok,blibli)}
        {--overwrite : Timpa pemetaan channel yang sudah ada (default: hanya isi yang kosong)}
        {--dry-run : Tampilkan ringkasan saja, tidak menulis apa pun}';

    protected $description = 'Impor pemetaan kategori internal -> kategori channel dari dump Jubelio (idempoten, upsert).';

    private const CHANNEL_FIELDS = [
        'shopee' => ['id' => 'shopee_category_id', 'name' => 'shopee_category_name'],
        'lazada' => ['id' => 'lazada_category_id', 'name' => 'lazada_category_name'],
        'tiktok' => ['id' => 'tiktok_category_id', 'name' => 'tiktok_category_name'],
        'blibli' => ['id' => 'blibli_category_id', 'name' => 'blibli_category_name'],
    ];

    public function handle(): int
    {
        $path = $this->argument('path')
            ?? __DIR__ . '/../../../database/data/jubelio_category_mappings.json';

        if (! is_readable($path)) {
            $this->error("File tidak terbaca: {$path}");
            return self::FAILURE;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        $rows = $decoded['data'] ?? (is_array($decoded) ? $decoded : null);
        if (! is_array($rows)) {
            $this->error('Format JSON tidak dikenali (harapkan { "data": [...] } atau array).');
            return self::FAILURE;
        }

        $requested = array_filter(array_map('trim', explode(',', (string) $this->option('channels'))));
        $channelIdByCode = [];
        foreach ($requested as $code) {
            if (! isset(self::CHANNEL_FIELDS[$code])) {
                $this->warn("Channel '{$code}' tidak dikenali, dilewati.");
                continue;
            }
            $id = DB::table('channels')->where('code', $code)->value('id');
            if (! $id) {
                $this->warn("Channel '{$code}' tidak ada di tabel channels, dilewati.");
                continue;
            }
            $channelIdByCode[$code] = $id;
        }
        if (! $channelIdByCode) {
            $this->error('Tidak ada channel valid untuk diproses.');
            return self::FAILURE;
        }

        [$pathToId, $leafToIds] = $this->buildInternalCategoryIndex();

        $overwrite = (bool) $this->option('overwrite');
        $dryRun = (bool) $this->option('dry-run');

        $matched = 0;
        $unmatched = [];
        $ambiguous = [];
        $catCache = [];
        $stats = [];
        foreach (array_keys($channelIdByCode) as $code) {
            $stats[$code] = ['cat_created' => 0, 'mapped' => 0, 'already' => 0, 'kept_manual' => 0, 'no_data' => 0];
        }

        DB::beginTransaction();
        try {
            foreach ($rows as $row) {
                $fullName = (string) ($row['full_category_name'] ?? '');
                $norm = $this->normalizePath($fullName);
                if ($norm === '') {
                    continue;
                }

                $internalId = $pathToId[$norm] ?? null;
                if (! $internalId) {
                    $leaf = $this->normalizeSegment((string) (array_slice(explode('>', $fullName), -1)[0] ?? ''));
                    $candidates = $leafToIds[$leaf] ?? [];
                    if (count($candidates) === 1) {
                        $internalId = $candidates[0];
                    } elseif (count($candidates) > 1) {
                        $ambiguous[] = $fullName;
                        continue;
                    }
                }
                if (! $internalId) {
                    $unmatched[] = $fullName;
                    continue;
                }
                $matched++;

                foreach ($channelIdByCode as $code => $channelId) {
                    $extId = $this->clean($row[self::CHANNEL_FIELDS[$code]['id']] ?? null);
                    if ($extId === null) {
                        $stats[$code]['no_data']++;
                        continue;
                    }
                    $name = $this->clean($row[self::CHANNEL_FIELDS[$code]['name']] ?? null) ?? $extId;

                    $channelCatId = $this->upsertChannelCategory($channelId, $extId, $name, $catCache, $stats[$code]);

                    $existing = DB::table('category_channel_mappings as m')
                        ->join('channel_categories as c', 'c.id', '=', 'm.channel_category_id')
                        ->where('m.category_id', $internalId)
                        ->where('c.channel_id', $channelId)
                        ->pluck('m.channel_category_id', 'm.id');

                    if ($existing->contains($channelCatId)) {
                        $stats[$code]['already']++;
                        continue;
                    }
                    if ($existing->isNotEmpty() && ! $overwrite) {
                        $stats[$code]['kept_manual']++;
                        continue;
                    }
                    if ($existing->isNotEmpty()) {
                        DB::table('category_channel_mappings')->whereIn('id', $existing->keys())->delete();
                    }
                    DB::table('category_channel_mappings')->insert([
                        'category_id' => $internalId,
                        'channel_category_id' => $channelCatId,
                        'is_stale' => false,
                        'last_verified_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $stats[$code]['mapped']++;
                }
            }

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Gagal: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->report($rows, $matched, $unmatched, $ambiguous, $stats, $dryRun, $overwrite);

        return self::SUCCESS;
    }

    private function buildInternalCategoryIndex(): array
    {
        $cats = DB::table('categories')->get(['id', 'parent_id', 'name']);
        $byId = [];
        foreach ($cats as $c) {
            $byId[$c->id] = $c;
        }

        $pathToId = [];
        $leafToIds = [];
        foreach ($cats as $c) {
            $parts = [$c->name];
            $cur = $c;
            $guard = 0;
            while ($cur->parent_id && isset($byId[$cur->parent_id]) && $guard++ < 20) {
                $cur = $byId[$cur->parent_id];
                array_unshift($parts, $cur->name);
            }
            $norm = $this->normalizePath(implode(' > ', $parts));
            // Path duplikat -> tandai null agar tidak salah pilih.
            $pathToId[$norm] = array_key_exists($norm, $pathToId) ? null : $c->id;

            $leaf = $this->normalizeSegment((string) $c->name);
            $leafToIds[$leaf][] = $c->id;
        }
        $pathToId = array_filter($pathToId, fn ($v) => $v !== null);

        return [$pathToId, $leafToIds];
    }

    private function upsertChannelCategory(string $channelId, string $extId, string $name, array &$cache, array &$stat): string
    {
        $key = $channelId . '|' . $extId;
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $existing = DB::table('channel_categories')
            ->where('channel_id', $channelId)
            ->where('external_id', $extId)
            ->first(['id']);

        if ($existing) {
            DB::table('channel_categories')->where('id', $existing->id)
                ->update(['name' => $name, 'updated_at' => now()]);
            return $cache[$key] = $existing->id;
        }

        $uuid = Uuid::uuid7()->toString();
        DB::table('channel_categories')->insert([
            'id' => $uuid,
            'channel_id' => $channelId,
            'external_id' => $extId,
            'parent_external_id' => '0',
            'name' => $name,
            'is_leaf' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $stat['cat_created']++;

        return $cache[$key] = $uuid;
    }

    private function clean(?string $value): ?string
    {
        $v = trim((string) $value);
        if ($v === '' || $v === '-') {
            return null;
        }
        return $v;
    }

    private function normalizePath(string $path): string
    {
        $segs = array_map(fn ($s) => $this->normalizeSegment($s), explode('>', $path));
        $segs = array_filter($segs, fn ($s) => $s !== '');
        return implode(' > ', $segs);
    }

    private function normalizeSegment(string $seg): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $seg)));
    }

    private function report(array $rows, int $matched, array $unmatched, array $ambiguous, array $stats, bool $dryRun, bool $overwrite): void
    {
        $this->newLine();
        $this->info($dryRun ? '=== DRY-RUN (tidak ada yang ditulis) ===' : '=== SELESAI (tersimpan) ===');
        $this->line('Mode pemetaan: ' . ($overwrite ? 'OVERWRITE' : 'fill-gap (tak menimpa manual)'));
        $this->line('Baris dump      : ' . count($rows));
        $this->line('Kategori cocok  : ' . $matched);
        $this->line('Tidak cocok     : ' . count($unmatched));
        $this->line('Ambigu (nama)   : ' . count($ambiguous));

        $this->newLine();
        $this->table(
            ['Channel', 'ChannelCat baru', 'Mapping baru', 'Sudah benar', 'Manual dijaga', 'Tanpa data'],
            array_map(fn ($code, $s) => [
                $code, $s['cat_created'], $s['mapped'], $s['already'], $s['kept_manual'], $s['no_data'],
            ], array_keys($stats), $stats),
        );

        foreach ($unmatched as $u) {
            $this->warn("  [tak cocok] {$u}");
        }
        foreach ($ambiguous as $a) {
            $this->warn("  [ambigu]    {$a}");
        }
    }
}
