<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Outbound\Database\Seeders\CourierSeeder;
use Modules\Outbound\Models\Courier;

/**
 * Sinkronkan master kurir global (`couriers` dengan `tenant_id = null`) agar
 * PERSIS sama dengan daftar kanonik di `CourierSeeder::canonicalNames()`
 * (bentuk Jubelio yang diminta user).
 *
 * Beda dengan `couriers:consolidate` (yang cuma menggabung varian per-brand):
 * command ini MEMANGKAS long-tail — setiap kurir aktif yang TIDAK ada di daftar
 * kanonik akan dinonaktifkan, dan setiap nama di daftar yang belum ada dibuat.
 *
 * Aman & reversible:
 * - Baris di luar daftar TIDAK dihapus, hanya `is_active = false`. Riwayat
 *   pengiriman aman karena `Shipment` menyimpan `courier_name` sebagai teks,
 *   bukan FK ke `couriers.id`.
 * - Idempotent: jalan kedua kali = "sudah sinkron", tidak ada perubahan.
 * - Default dry-run (laporan saja). Pakai `--apply` untuk eksekusi.
 */
class SyncMasterCouriersCommand extends Command
{
    protected $signature = 'couriers:sync-master {--apply : Terapkan perubahan (default: dry-run, hanya laporan)}';

    protected $description = 'Sinkronkan master kurir global agar persis sama dengan daftar kanonik (nonaktifkan yang di luar daftar, tambah yang kurang)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        // key ternormalisasi (lowercase + trim, TANPA hapus spasi supaya
        // "Indo Paket" != "Indopaket") => nama kanonik dengan casing final.
        $targets = [];
        foreach (CourierSeeder::canonicalNames() as $name) {
            $normalized = trim($name);
            if ($normalized === '') {
                continue;
            }
            $targets[$this->key($normalized)] = $normalized;
        }

        $existing = Courier::whereNull('tenant_id')->orderBy('name')->get();
        $existingByKey = $existing->groupBy(fn (Courier $c) => $this->key($c->name));

        $toCreate = [];      // nama kanonik yang belum ada
        $toUpdate = [];      // [row, namaBaru] — perbaiki casing / reaktivasi
        $toDeactivate = [];  // baris aktif di luar daftar (termasuk duplikat)

        // 1. Pastikan setiap target ada, aktif, dan casing-nya benar.
        foreach ($targets as $key => $canonicalName) {
            $group = $existingByKey->get($key);
            if ($group === null || $group->isEmpty()) {
                $toCreate[] = $canonicalName;
                continue;
            }

            // Prioritas: baris yang sudah aktif, kalau tidak ada ambil pertama.
            $keep = $group->firstWhere('is_active', true) ?? $group->first();
            if ($keep->name !== $canonicalName || ! $keep->is_active) {
                $toUpdate[] = [$keep, $canonicalName];
            }
            // Duplikat lain dalam grup yang sama → nonaktifkan.
            foreach ($group as $row) {
                if ($row->id !== $keep->id && $row->is_active) {
                    $toDeactivate[] = $row;
                }
            }
        }

        // 2. Nonaktifkan semua baris aktif yang key-nya tidak ada di target.
        foreach ($existing as $row) {
            if (! array_key_exists($this->key($row->name), $targets) && $row->is_active) {
                $toDeactivate[] = $row;
            }
        }

        $this->info(sprintf(
            '%s Target kanonik: %d | Buat: %d | Perbaiki/aktifkan: %d | Nonaktifkan: %d',
            $apply ? 'APPLY:' : 'DRY-RUN:',
            count($targets),
            count($toCreate),
            count($toUpdate),
            count($toDeactivate),
        ));

        if (empty($toCreate) && empty($toUpdate) && empty($toDeactivate)) {
            $this->info('Master kurir sudah persis sesuai daftar kanonik. Tidak ada perubahan.');
            return self::SUCCESS;
        }

        $this->report('Akan DIBUAT', collect($toCreate)->map(fn ($n) => "  + {$n}"));
        $this->report('Akan DIPERBAIKI/DIAKTIFKAN', collect($toUpdate)->map(
            fn ($u) => "  ~ \"{$u[0]->name}\" → \"{$u[1]}\"" . ($u[0]->is_active ? '' : ' (reaktivasi)')
        ));
        $this->report('Akan DINONAKTIFKAN', collect($toDeactivate)->map(fn ($r) => "  - {$r->name} [{$r->code}]"));

        if (! $apply) {
            $this->warn('');
            $this->warn('Ini dry-run — tidak ada perubahan disimpan. Jalankan ulang dengan --apply untuk eksekusi.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($toCreate, $toUpdate, $toDeactivate) {
            foreach ($toCreate as $name) {
                Courier::create([
                    'name'       => $name,
                    'code'       => CourierSeeder::uniqueCodeFor($name),
                    'is_active'  => true,
                    'tenant_id'  => null,
                ]);
            }
            foreach ($toUpdate as [$row, $name]) {
                $row->update(['name' => $name, 'is_active' => true]);
            }
            foreach ($toDeactivate as $row) {
                $row->update(['is_active' => false]);
            }
        });

        $this->info('');
        $this->info('Selesai. Master kurir kini persis sesuai daftar kanonik (baris lain dinonaktifkan, bukan dihapus).');

        return self::SUCCESS;
    }

    private function key(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    private function report(string $title, \Illuminate\Support\Collection $lines): void
    {
        if ($lines->isEmpty()) {
            return;
        }
        $this->line('');
        $this->line("<comment>{$title} ({$lines->count()}):</comment>");
        $lines->each(fn ($l) => $this->line($l));
    }
}
