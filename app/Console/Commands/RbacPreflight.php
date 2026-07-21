<?php

namespace App\Console\Commands;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Console\Command;
use Modules\Auth\Support\PermissionCatalog;

class RbacPreflight extends Command
{
    protected $signature = 'rbac:preflight {--json : Keluarkan sebagai JSON untuk diarsipkan}';

    protected $description = 'Bandingkan permission RBAC di database dengan config/rbac.php (read-only)';

    public function handle(): int
    {
        $catalog = PermissionCatalog::allPermissionNames();
        $inDb = Permission::pluck('name')->all();

        $missingInDb = array_values(array_diff($catalog, $inDb));
        $extraInDb = array_values(array_diff($inDb, $catalog));

        $roles = [];
        foreach (config('rbac.defaults', []) as $roleName => $tokens) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            $current = $role ? $role->permissions->pluck('name')->all() : null;
            $target = PermissionCatalog::resolveGrants($tokens);

            $roles[$roleName] = [
                'ada_di_db' => $role !== null,
                'jumlah_user' => $role ? $role->users()->count() : 0,
                'sekarang' => $current === null ? null : count($current),
                'setelah_seed' => count($target),
                'akan_hilang' => $current === null ? [] : array_values(array_diff($current, $target)),
                'akan_didapat' => $current === null ? $target : array_values(array_diff($target, $current)),
            ];
        }

        $orphanRoles = Role::whereNotIn('name', array_keys(config('rbac.defaults', [])))
            ->get()
            ->map(fn ($r) => ['nama' => $r->name, 'user' => $r->users()->count(), 'permission' => $r->permissions()->count()])
            ->all();

        if ($this->option('json')) {
            $this->line(json_encode([
                'permission_kurang_di_db' => $missingInDb,
                'permission_asing_di_db' => $extraInDb,
                'role' => $roles,
                'role_tanpa_defaults' => $orphanRoles,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('== Katalog vs tabel permissions ==');
        $this->line(sprintf('   katalog: %d  |  database: %d', count($catalog), count($inDb)));
        $this->line('   perlu DIBUAT oleh seeder : '.(count($missingInDb) ? implode(', ', $missingInDb) : 'tidak ada'));
        $this->line('   ada di DB tapi bukan katalog: '.(count($extraInDb) ? implode(', ', $extraInDb) : 'tidak ada'));
        $this->newLine();

        $this->info('== Dampak seeder per role ==');
        $rows = [];
        foreach ($roles as $name => $r) {
            $rows[] = [
                $name,
                $r['jumlah_user'],
                $r['ada_di_db'] ? $r['sekarang'] : '(role belum ada)',
                $r['setelah_seed'],
                count($r['akan_hilang']),
            ];
        }
        $this->table(['Role', 'User', 'Permission sekarang', 'Setelah seed', 'HILANG'], $rows);

        $anyLoss = false;
        foreach ($roles as $name => $r) {
            if ($r['akan_hilang'] !== []) {
                $anyLoss = true;
                $this->warn(sprintf('   %s akan KEHILANGAN %d permission:', $name, count($r['akan_hilang'])));
                $this->line('      '.implode(', ', $r['akan_hilang']));
            }
        }

        if ($orphanRoles !== []) {
            $this->newLine();
            $this->warn('== Role di DB yang tidak ada di config defaults (tidak akan disentuh seeder) ==');
            foreach ($orphanRoles as $r) {
                $this->line(sprintf('   %s — %d user, %d permission', $r['nama'], $r['user'], $r['permission']));
            }
        }

        $this->newLine();
        if ($anyLoss) {
            $this->error('ADA role yang kehilangan permission. Backup dulu sebelum seed:');
            $this->line('   pg_dump -t role_has_permissions -t model_has_roles -t model_has_permissions <db> > rbac-backup.sql');
            $this->line('Kalau kehilangan itu tidak disengaja, tambahkan permission tersebut ke config/rbac.php dulu.');

            return self::FAILURE;
        }

        $this->info('Tidak ada role yang kehilangan permission. Aman untuk menjalankan:');
        $this->line('   php artisan db:seed --class=RbacPermissionSeeder --force');

        return self::SUCCESS;
    }
}
