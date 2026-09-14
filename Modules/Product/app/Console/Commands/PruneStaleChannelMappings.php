<?php

declare(strict_types=1);

namespace Modules\Product\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Modules\Product\Services\StaleChannelMappingPruneService;

final class PruneStaleChannelMappings extends Command
{
    protected $signature = 'products:prune-stale-channel-mappings
                            {--apply : Hapus mapping stale yang lolos precondition}
                            {--confirm= : Wajib PRUNE-STALE-CHANNEL-MAPPINGS saat --apply}
                            {--channel= : Batasi kode channel}
                            {--shop= : Batasi shop_id eksternal}
                            {--limit=25 : Jumlah parent mapping per batch}';

    protected $description = 'Pratinjau dan hapus mapping channel yang menunjuk ke master atau varian yang sudah dihapus';

    public function __construct(private readonly StaleChannelMappingPruneService $pruner)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        if ($limit < 1 || $limit > 250) {
            $this->error('--limit harus antara 1 dan 250 parent mapping.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');

        if ($apply && $this->option('confirm') !== 'PRUNE-STALE-CHANNEL-MAPPINGS') {
            $this->error('Konfirmasi tidak cocok. Gunakan --confirm=PRUNE-STALE-CHANNEL-MAPPINGS.');

            return self::FAILURE;
        }

        if ($apply && ! Schema::hasTable('channel_mapping_prune_audits')) {
            $this->error('Migration audit belum aktif. Tidak ada data yang diubah.');

            return self::FAILURE;
        }

        $rows = $this->pruner->candidates(
            $this->option('channel'),
            $this->option('shop'),
            $limit,
        );

        $this->line('parent mapping dalam batch: '.$rows->count());
        $this->line('child stale dalam batch: '.$rows->sum('stale_children'));

        foreach ($rows as $row) {
            $this->line(sprintf(
                '%s | %s | listing=%s | stale_parent=%s | stale_children=%s | total_children=%s',
                $row->channel,
                $row->shop_name,
                $row->listing ?? 'NULL',
                $row->stale_parent ? 'YES' : 'NO',
                $row->stale_children,
                $row->child_mappings,
            ));
        }

        if (! $apply) {
            $this->warn('DRY-RUN — database tidak diubah. Tambahkan --apply --confirm=PRUNE-STALE-CHANNEL-MAPPINGS setelah meninjau batch.');

            return self::SUCCESS;
        }

        $pruned = 0;
        $children = 0;
        $parents = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            try {
                $result = $this->pruner->prune((string) $row->mapping_id);
                $pruned++;
                $children += $result['deleted_children'];
                $parents += $result['deleted_parent'] ? 1 : 0;
                $this->info('PRUNED listing='.($row->listing ?? 'NULL').' | reason='.$result['reason']);
            } catch (\DomainException $exception) {
                $skipped++;
                $this->warn('SKIPPED listing='.($row->listing ?? 'NULL').' | '.$exception->getMessage());
            }
        }

        $this->info("RESULT pruned={$pruned} | child_mappings={$children} | parent_mappings={$parents} | skipped={$skipped}");

        return $skipped === 0 ? self::SUCCESS : self::FAILURE;
    }
}
