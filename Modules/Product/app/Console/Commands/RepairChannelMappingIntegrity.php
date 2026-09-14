<?php

namespace Modules\Product\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Modules\Product\Services\ChannelMappingRepairService;

final class RepairChannelMappingIntegrity extends Command
{
    protected $signature = 'products:repair-channel-mapping-integrity
                            {--apply : Terapkan perbaikan yang lolos semua precondition}
                            {--confirm= : Wajib REASSIGN-CHANNEL-LISTINGS saat --apply}
                            {--channel= : Batasi kode channel, misalnya shopee}
                            {--shop= : Batasi shop_id eksternal}
                            {--limit=25 : Jumlah listing aman per batch}';

    protected $description = 'Perbaiki induk listing channel lama yang seluruh variannya sudah konsisten berada pada satu master lain';

    public function __construct(private readonly ChannelMappingRepairService $repair)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        if ($limit < 1 || $limit > 250) {
            $this->error('--limit harus antara 1 dan 250 listing.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');

        if ($apply && $this->option('confirm') !== 'REASSIGN-CHANNEL-LISTINGS') {
            $this->error('Konfirmasi tidak cocok. Gunakan --confirm=REASSIGN-CHANNEL-LISTINGS.');

            return self::FAILURE;
        }

        if ($apply && ! Schema::hasTable('channel_mapping_repair_audits')) {
            $this->error('Migration audit belum aktif. Jalankan deploy/migrasi terlebih dahulu; tidak ada data yang diubah.');

            return self::FAILURE;
        }

        $rows = $this->repair->safeParentReassignments(
            $this->option('channel'),
            $this->option('shop'),
            $limit,
        );

        $this->line('listing aman dalam batch: '.$rows->count());
        $this->line('varian dalam batch: '.$rows->sum('models'));

        foreach ($rows as $row) {
            $this->line(sprintf(
                '%s | %s | listing=%s | models=%s',
                $row->channel,
                $row->shop_name,
                $row->listing,
                $row->models,
            ));
        }

        if (! $apply) {
            $this->warn('DRY-RUN — tidak ada data yang diubah. Tambahkan --apply --confirm=REASSIGN-CHANNEL-LISTINGS setelah meninjau batch ini.');

            return self::SUCCESS;
        }

        $repaired = 0;
        $models = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            try {
                $result = $this->repair->reassign((string) $row->mapping_id);
                $repaired++;
                $models += $result['models'];
                $this->info('REPAIRED listing='.$row->listing.' | models='.$result['models']);
            } catch (\DomainException $exception) {
                $skipped++;
                $this->warn('SKIPPED listing='.$row->listing.' | '.$exception->getMessage());
            }
        }

        $this->info("RESULT repaired={$repaired} | models={$models} | skipped={$skipped}");

        return $skipped === 0 ? self::SUCCESS : self::FAILURE;
    }
}
