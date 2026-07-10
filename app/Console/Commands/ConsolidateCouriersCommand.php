<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Outbound\Database\Seeders\CourierSeeder;
use Modules\Outbound\Models\Courier;
use Modules\Outbound\Models\CourierChannelMapping;
use Modules\Outbound\Services\CourierMappingService;

class ConsolidateCouriersCommand extends Command
{
    protected $signature = 'couriers:consolidate {--apply : Terapkan perubahan (default: dry-run, hanya laporan)}';

    protected $description = 'Gabungkan baris Courier mentah per-varian-layanan menjadi satu baris kanonik per kurir';

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

        $canonicalKeys = collect(CourierSeeder::canonicalNames())
            ->mapWithKeys(fn (string $n) => [mb_strtolower(trim($n)) => true]);
        $isCanonical = fn (Courier $c) => $canonicalKeys->has(mb_strtolower(trim($c->name)));

        $groups = $couriers->groupBy(fn (Courier $c) => $this->mapper->resolveCode($c->name));

        $toConsolidate = $groups->filter(
            fn ($rows) => $rows->count() > 1 && $rows->reject($isCanonical)->isNotEmpty()
        );

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

        $run = function () use ($toConsolidate, $apply, $isCanonical) {
            foreach ($toConsolidate as $code => $rows) {

                $rawRows = $rows->reject($isCanonical)->values();
                $canonicalName = $this->canonicalNames[$code]
                    ?? $rows->sortBy(fn (Courier $c) => strlen($c->name))->first()->name;

                $this->line("");
                $this->line("<comment>{$code}</comment> → \"{$canonicalName}\" ({$rawRows->count()} baris mentah dilebur)");
                foreach ($rawRows as $row) {
                    $this->line("  - {$row->name} [{$row->code}]");
                }

                if (! $apply) {
                    continue;
                }

                $canonical = $rows->first(
                    fn (Courier $c) => mb_strtolower(trim($c->name)) === mb_strtolower($canonicalName)
                );
                if ($canonical) {
                    if ($canonical->name !== $canonicalName || ! $canonical->is_active) {
                        $canonical->update(['name' => $canonicalName, 'is_active' => true]);
                    }
                } else {
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
                }

                foreach ($rawRows as $row) {
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
