<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Outbound\Models\Courier;
use Modules\Outbound\Models\CourierChannelMapping;
use Modules\Outbound\Services\CourierMappingService;

/**
 * Konsolidasi baris `couriers` mentah (hasil seed dari nama kurir per-varian
 * layanan channel, mis. "JNE REG"/"JNE YES"/"JNE OKE") menjadi satu baris
 * kanonik per kurir riil (mis. "JNE") — sesuai bentuk master kurir Jubelio.
 *
 * Aman dijalankan berkali-kali:
 * - Baris lama TIDAK dihapus, hanya di-nonaktifkan (`is_active = false`).
 * - Jejak nama mentah dipindahkan ke `courier_channel_mappings` supaya order
 *   masuk berikutnya dengan nama yang sama tetap ter-mapping ke kurir kanonik.
 * - Default jalan sebagai dry-run (laporan saja). Pakai --apply untuk eksekusi.
 */
class ConsolidateCouriersCommand extends Command
{
    protected $signature = 'couriers:consolidate {--apply : Terapkan perubahan (default: dry-run, hanya laporan)}';

    protected $description = 'Gabungkan baris Courier mentah per-varian-layanan menjadi satu baris kanonik per kurir';

    /**
     * Nama tampilan kanonik untuk kode kurir yang sudah dikenal
     * CourierMappingService::$codeAliases. Kalau kode tidak ada di sini,
     * dipakai nama existing terpendek dalam grup sebagai kanonik.
     */
    private array $canonicalNames = [
        'jne' => 'JNE',
        'jnt' => 'J&T',
        'sicepat' => 'SiCepat',
        'anteraja' => 'AnterAja',
        'ninja' => 'Ninja Xpress',
        'spx' => 'SPX',
        'idexpress' => 'ID Express',
        'lionparcel' => 'Lion Parcel',
        'gosend' => 'GoSend',
        'grabexpress' => 'GrabExpress',
        'pos' => 'Pos Indonesia',
        'tiki' => 'TIKI',
        'sap' => 'SAP Express',
        'wahana' => 'Wahana',
        'rex' => 'REX',
        'paxel' => 'Paxel',
        'lex' => 'LEX',
    ];

    public function __construct(
        private readonly CourierMappingService $mapper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $couriers = Courier::whereNull('tenant_id')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $groups = $couriers->groupBy(fn (Courier $c) => $this->mapper->resolveCode($c->name));
        $toConsolidate = $groups->filter(fn ($rows) => $rows->count() > 1);

        if ($toConsolidate->isEmpty()) {
            $this->info('Tidak ada grup kurir mentah yang perlu dikonsolidasi. Master kurir sudah bersih.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s Ditemukan %d kurir kanonik yang perlu dikonsolidasi dari %d baris mentah.',
            $apply ? 'APPLY:' : 'DRY-RUN:',
            $toConsolidate->count(),
            $toConsolidate->sum(fn ($rows) => $rows->count()),
        ));

        $run = function () use ($toConsolidate, $apply) {
            foreach ($toConsolidate as $code => $rows) {
                $canonicalName = $this->canonicalNames[$code]
                    ?? $rows->sortBy(fn (Courier $c) => strlen($c->name))->first()->name;

                $this->line("");
                $this->line("<comment>{$code}</comment> → \"{$canonicalName}\" ({$rows->count()} baris)");
                foreach ($rows as $row) {
                    $this->line("  - {$row->name} [{$row->code}]");
                }

                if (! $apply) {
                    continue;
                }

                $canonical = Courier::firstOrCreate(
                    ['code' => $code],
                    ['name' => $canonicalName, 'is_active' => true],
                );
                if (! $canonical->wasRecentlyCreated && $canonical->name !== $canonicalName) {
                    $canonical->update(['name' => $canonicalName]);
                }
                if (! $canonical->is_active) {
                    $canonical->update(['is_active' => true]);
                }

                foreach ($rows as $row) {
                    if ($row->id === $canonical->id) {
                        continue;
                    }

                    CourierChannelMapping::updateOrCreate(
                        [
                            'channel_code' => 'legacy',
                            'external_name' => $row->name,
                            'tenant_id' => null,
                        ],
                        [
                            'courier_id' => $canonical->id,
                            'is_verified' => true,
                        ],
                    );

                    $row->update(['is_active' => false]);
                }
            }
        };

        if ($apply) {
            DB::transaction($run);
            $this->info('');
            $this->info('Selesai. Baris mentah dinonaktifkan, jejak tersimpan di courier_channel_mappings.');
        } else {
            $run();
            $this->info('');
            $this->warn('Ini dry-run — tidak ada perubahan disimpan. Jalankan ulang dengan --apply untuk eksekusi.');
        }

        return self::SUCCESS;
    }
}
