<?php

declare(strict_types=1);

namespace Modules\Product\Console\Commands;

use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Modules\Product\Services\MixedChannelMappingSplitService;

final class SplitMixedChannelMappings extends Command
{
    protected $signature = 'products:split-mixed-channel-mappings
                            {--apply : Terapkan pemecahan mapping yang lolos seluruh precondition}
                            {--confirm= : Wajib SPLIT-MIXED-CHANNEL-MAPPINGS saat --apply}
                            {--channel= : Batasi kode channel}
                            {--shop= : Batasi shop_id eksternal}
                            {--limit=25 : Jumlah mapping induk per batch}';

    protected $description = 'Pisahkan mapping listing lama yang mencampur varian dari beberapa master produk';

    public function __construct(private readonly MixedChannelMappingSplitService $splitter)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        if ($limit < 1 || $limit > 250) {
            $this->error('--limit harus antara 1 dan 250 mapping induk.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');

        if ($apply && $this->option('confirm') !== 'SPLIT-MIXED-CHANNEL-MAPPINGS') {
            $this->error('Konfirmasi tidak cocok. Gunakan --confirm=SPLIT-MIXED-CHANNEL-MAPPINGS.');

            return self::FAILURE;
        }

        if ($apply && ! Schema::hasTable('channel_mapping_split_audits')) {
            $this->error('Migration audit belum aktif. Tidak ada data yang diubah.');

            return self::FAILURE;
        }

        $plans = $this->splitter->candidates(
            $this->option('channel'),
            $this->option('shop'),
            $limit,
        );

        $this->line('mapping induk aman dalam batch: '.$plans->count());
        $this->line('varian yang akan dipindahkan: '.$plans->sum('moved_models'));
        $this->line('mapping induk baru yang akan dibuat: '.$plans->sum('created_parents'));

        foreach ($plans as $plan) {
            $this->line(sprintf(
                '%s | %s | listing=%s | models=%s | parent_baru=%s',
                $plan->channel,
                $plan->shop_name,
                $plan->listing ?? 'NULL',
                $plan->moved_models,
                $plan->created_parents,
            ));
        }

        if (! $apply) {
            $this->warn('DRY-RUN — database tidak diubah. Tambahkan --apply --confirm=SPLIT-MIXED-CHANNEL-MAPPINGS setelah meninjau batch.');

            return self::SUCCESS;
        }

        $repaired = 0;
        $movedModels = 0;
        $createdParents = 0;
        $skipped = 0;

        foreach ($plans as $plan) {
            try {
                $result = $this->splitter->split($plan->mapping_id);
                $repaired++;
                $movedModels += $result['moved_models'];
                $createdParents += $result['created_parents'];
                $this->info('SPLIT listing='.($result['listing'] ?? 'NULL').' | models='.$result['moved_models']);
            } catch (DomainException $exception) {
                $skipped++;
                $this->warn('SKIPPED listing='.($plan->listing ?? 'NULL').' | '.$exception->getMessage());
            }
        }

        $this->info("RESULT repaired={$repaired} | moved_models={$movedModels} | created_parents={$createdParents} | skipped={$skipped}");

        return $skipped === 0 ? self::SUCCESS : self::FAILURE;
    }
}
