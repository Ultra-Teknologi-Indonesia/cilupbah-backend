<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class AuditLabelCapacity extends Command
{
    protected $signature = 'channel:audit-label-capacity {--json}';

    protected $description = 'Read-only audit of configured label worker budgets and queue visibility timeouts; no channel calls.';

    public function handle(): int
    {
        $definitions = (array) config('horizon.queue_health_supervisors', []);
        $profiles = [];
        $issues = [];
        foreach (['labels-awb', 'labels-pdf', 'order-intake', 'stock'] as $profile) {
            $names = (array) config("horizon.profiles.{$profile}", []);
            $overhead = (count($names) + 1) * (int) config('horizon.resident_process_budget_mb', 128);
            $workerMb = 0;
            $workers = 0;
            foreach ($names as $name) {
                $pool = $definitions[$name] ?? [];
                $workers += (int) ($pool['maxProcesses'] ?? 0);
                $workerMb += (int) ($pool['maxProcesses'] ?? 0) * (int) ($pool['memory'] ?? 0);
                $connection = (string) ($pool['connection'] ?? '');
                $retryAfter = (int) config("queue.connections.{$connection}.retry_after", 0);
                if ($retryAfter <= (int) ($pool['timeout'] ?? 0)) {
                    $issues[] = "{$name}: retry_after harus lebih besar dari timeout worker.";
                }
            }
            $limit = (int) config("horizon.profile_memory_limits_mb.{$profile}", 0);
            $required = $workerMb + $overhead;
            if ($names === [] || $required > $limit) {
                $issues[] = "{$profile}: anggaran worker melebihi limit atau profil tidak ditemukan.";
            }
            $profiles[$profile] = [
                'max_workers_per_pod' => $workers, 'worker_recycle_budget_mb' => $workerMb,
                'estimated_process_overhead_mb' => $overhead, 'configured_pod_limit_mb' => $limit,
                'remaining_budget_mb' => $limit - $required,
            ];
        }
        $cacheStore = (string) (config('cache.limiter') ?: config('cache.default'));
        $archiveDisk = (string) config('bulk-labels.archive_disk', 'documents');
        $cacheDriver = config("cache.stores.{$cacheStore}.driver");
        if (app()->environment('production') && $cacheDriver !== 'redis') {
            $issues[] = 'Limiter harus memakai Redis bersama pada deployment multi-pod; periksa cache.limiter/cache.default.';
        }
        $result = [
            'generated_at' => now()->toIso8601String(),
            'active_profile' => config('horizon.active_profile'),
            'status' => $issues === [] ? 'configuration_checks_passed' : 'review_required',
            'profiles' => $profiles,
            'async_shopee_preparation' => (bool) config('bulk-labels.async_shopee_preparation'),
            'tiktok_package_cap' => (int) config('bulk-labels.tiktok_mass_awb_chunk_size'),
            'local_first' => (bool) config('bulk-labels.local_first'),
            'archive_disk' => config('bulk-labels.archive_disk'),
            'archive_driver' => config("filesystems.disks.{$archiveDisk}.driver"),
            'archive_uses_r2_endpoint' => str_ends_with((string) parse_url((string) config("filesystems.disks.{$archiveDisk}.endpoint", ''), PHP_URL_HOST), '.r2.cloudflarestorage.com'),
            'limiter_cache_driver' => $cacheDriver,
            'issues' => $issues,
            'limitations' => [
                'Ini bukan load test dan tidak membuktikan latency atau kapasitas server.',
                'Batas memory Horizon adalah pemicu recycle, bukan hard cap memori per job.',
                'Periksa pod aktual, replica/surge, proses native PDF, Redis, database dan node; anggaran ini per pod.',
                'Waktu penerbitan resi/label ditentukan juga oleh marketplace.',
            ],
        ];
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $issues === [] ? self::SUCCESS : self::FAILURE;
    }
}
