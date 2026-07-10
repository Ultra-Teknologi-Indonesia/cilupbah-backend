<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Outbound\Database\Seeders\CourierSeeder;
use Modules\Outbound\Models\Courier;

class SyncMasterCouriersCommand extends Command
{
    protected $signature = 'couriers:sync-master {--apply : Terapkan perubahan (default: dry-run, hanya laporan)}';

    protected $description = 'Sinkronkan master kurir global agar persis sama dengan daftar kanonik (nonaktifkan yang di luar daftar, tambah yang kurang)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

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

        $toCreate = [];      
        $toUpdate = [];      
        $toDeactivate = [];  

        foreach ($targets as $key => $canonicalName) {
            $group = $existingByKey->get($key);
            if ($group === null || $group->isEmpty()) {
                $toCreate[] = $canonicalName;
                continue;
            }

            $keep = $group->firstWhere('is_active', true) ?? $group->first();
            if ($keep->name !== $canonicalName || ! $keep->is_active) {
                $toUpdate[] = [$keep, $canonicalName];
            }

            foreach ($group as $row) {
                if ($row->id !== $keep->id && $row->is_active) {
                    $toDeactivate[] = $row;
                }
            }
        }

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
