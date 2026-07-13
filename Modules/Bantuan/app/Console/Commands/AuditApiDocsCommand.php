<?php

namespace Modules\Bantuan\Console\Commands;

use Illuminate\Console\Command;
use Modules\Bantuan\Services\ApiDocsGenerator;

class AuditApiDocsCommand extends Command
{
    protected $signature = 'bantuan:audit-docs
        {--module= : Filter satu modul (slug lowercase)}
        {--json : Output JSON, bukan tabel}';

    protected $description = 'Audit endpoint mana yang belum ada PHPDoc @summary / @purpose.';

    public function handle(ApiDocsGenerator $generator): int
    {
        $doc = $generator->build();

        $modules = $doc['modules'];
        if ($filter = $this->option('module')) {
            $modules = array_values(array_filter($modules, fn ($m) => $m['slug'] === strtolower($filter)));
        }

        $rows = [];
        foreach ($modules as $mod) {
            $total = $mod['endpoints_count'];
            $undoc = $mod['undocumented'];
            $pct   = $total > 0 ? round((($total - $undoc) / $total) * 100, 1) : 100;
            $rows[] = [
                'module' => $mod['slug'],
                'total'  => $total,
                'documented' => $total - $undoc,
                'undocumented' => $undoc,
                'coverage' => "{$pct}%",
            ];
        }

        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->table(['Modul', 'Total', 'Terdokumentasi', 'Belum', 'Coverage'], array_map(fn ($r) => array_values($r), $rows));

        $totalUndoc = array_sum(array_column($rows, 'undocumented'));
        $totalAll   = array_sum(array_column($rows, 'total'));
        $overall    = $totalAll > 0 ? round((($totalAll - $totalUndoc) / $totalAll) * 100, 1) : 100;
        $this->info("Total: {$totalAll} endpoint, {$totalUndoc} belum ber-PHPDoc ({$overall}% coverage).");

        return self::SUCCESS;
    }
}
