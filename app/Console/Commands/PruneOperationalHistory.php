<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PruneOperationalHistory extends Command
{
    protected $signature = 'operations:prune-history
        {--dry-run : Hitung kandidat tanpa menghapus data}
        {--batch= : Jumlah maksimum baris per delete statement}
        {--max-rows= : Jumlah maksimum baris per sumber data dalam satu proses}';

    protected $description = 'Hapus riwayat operasional yang melewati retensi secara bertahap dan aman untuk database aktif.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $batchSize = max(100, (int) ($this->option('batch') ?: config('operational-retention.batch_size')));
        $maxRows = max($batchSize, (int) ($this->option('max-rows') ?: config('operational-retention.max_rows_per_resource')));

        $results = [
            'failed_jobs' => $this->prune(
                table: 'failed_jobs',
                dateColumn: 'failed_at',
                cutoff: now()->subHours((int) config('operational-retention.failed_jobs_hours')),
                batchSize: $batchSize,
                maxRows: $maxRows,
                dryRun: $dryRun,
            ),
            'notifications' => $this->prune(
                table: 'notifications',
                dateColumn: 'created_at',
                cutoff: now()->subHours((int) config('operational-retention.notifications_hours')),
                batchSize: $batchSize,
                maxRows: $maxRows,
                dryRun: $dryRun,
            ),
            'channel_webhook_inbox' => $this->prune(
                table: 'channel_webhook_inbox',
                dateColumn: 'processed_at',
                cutoff: now()->subHours((int) config('operational-retention.webhook_completed_hours')),
                batchSize: $batchSize,
                maxRows: $maxRows,
                dryRun: $dryRun,
                constrain: fn (Builder $query) => $query->whereIn('status', ['PROCESSED', 'SKIPPED']),
            ),
        ];

        foreach ($results as $source => $result) {
            $this->line(sprintf(
                '%s: %s %d baris%s.',
                $source,
                $dryRun ? 'kandidat' : 'dihapus',
                $result['rows'],
                $result['limited'] ? ' (mencapai batas proses)' : '',
            ));
        }

        $this->comment($dryRun
            ? 'Dry run: tidak ada data yang dihapus.'
            : 'Webhook FAILED dan RECEIVED tidak pernah disentuh oleh perintah ini.');

        return self::SUCCESS;
    }

    private function prune(
        string $table,
        string $dateColumn,
        Carbon $cutoff,
        int $batchSize,
        int $maxRows,
        bool $dryRun,
        ?callable $constrain = null,
    ): array {
        if (! Schema::hasTable($table)) {
            return ['rows' => 0, 'limited' => false];
        }

        $baseQuery = DB::table($table)
            ->whereNotNull($dateColumn)
            ->where($dateColumn, '<', $cutoff);

        if ($constrain !== null) {
            $constrain($baseQuery);
        }

        if ($dryRun) {
            $candidateIds = (clone $baseQuery)
                ->orderBy($dateColumn)
                ->orderBy('id')
                ->limit($maxRows)
                ->pluck('id');

            return [
                'rows' => $candidateIds->count(),
                'limited' => $candidateIds->count() === $maxRows
                    && (clone $baseQuery)->skip($maxRows)->exists(),
            ];
        }

        $deleted = 0;

        while ($deleted < $maxRows) {
            $ids = (clone $baseQuery)
                ->orderBy($dateColumn)
                ->orderBy('id')
                ->limit(min($batchSize, $maxRows - $deleted))
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += DB::table($table)->whereIn('id', $ids)->delete();
        }

        return [
            'rows' => $deleted,
            'limited' => $deleted === $maxRows && (clone $baseQuery)->exists(),
        ];
    }
}
