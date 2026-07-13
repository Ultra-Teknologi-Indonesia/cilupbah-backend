<?php

namespace Modules\Bantuan\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Modules\Bantuan\Services\ApiDocsGenerator;

class ExportApiDocsCommand extends Command
{
    protected $signature = 'bantuan:export-docs
        {--out= : Target direktori output (default: storage/app/bantuan)}
        {--fe : Sekaligus salin ke cilupbah-fe/public/bantuan/}';

    protected $description = 'Generate dokumentasi API (semua modul) sebagai JSON untuk halaman Bantuan.';

    public function handle(ApiDocsGenerator $generator): int
    {
        $outDir = $this->option('out') ?: storage_path('app/bantuan');
        File::ensureDirectoryExists($outDir);
        File::ensureDirectoryExists($outDir . '/modules');

        $this->line('Membangun dokumentasi API...');
        $files = $generator->buildPerModule();

        foreach ($files as $rel => $data) {
            $path = $outDir . '/' . $rel;
            File::ensureDirectoryExists(dirname($path));
            File::put($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        $index    = $files['index.json'];
        $totals   = $index['totals'];
        $this->info("OK — {$totals['modules']} modul, {$totals['endpoints']} endpoint ({$totals['undocumented']} belum ada PHPDoc).");
        $this->line("Output: {$outDir}/index.json + " . count($files) . ' file');

        if ($this->option('fe')) {
            $feDir = base_path('../cilupbah-fe/public/bantuan');
            if (! is_dir(dirname($feDir))) {
                $this->warn("Direktori FE tidak ditemukan: {$feDir}, skip.");
            } else {
                File::ensureDirectoryExists($feDir);
                File::ensureDirectoryExists($feDir . '/modules');
                foreach ($files as $rel => $data) {
                    $path = $feDir . '/' . $rel;
                    File::ensureDirectoryExists(dirname($path));
                    File::put($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                }
                $this->info("Disalin ke: {$feDir}");
            }
        }

        return self::SUCCESS;
    }
}
