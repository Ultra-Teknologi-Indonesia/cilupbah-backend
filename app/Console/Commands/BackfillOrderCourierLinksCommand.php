<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Outbound\Services\CourierMappingService;

class BackfillOrderCourierLinksCommand extends Command
{
    protected $signature = 'couriers:backfill-order-links {--apply : Terapkan perubahan (default: dry-run, hanya laporan)}';

    protected $description = 'Isi courier_id (kurir master) & resolved_shipment_type pesanan lama dari shipping_provider (idempoten)';

    public function __construct(
        private readonly CourierMappingService $mapper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $scanned = 0;
        $setCourier = 0;
        $setType = 0;
        $typeDist = [];
        $sample = [];

        DB::table('sales_orders')
            ->whereNotNull('shipping_provider')
            ->where('shipping_provider', '!=', '')
            ->orderBy('id')
            ->select('id', 'shipping_provider', 'courier_id', 'resolved_shipment_type')
            ->chunkById(500, function ($rows) use (&$scanned, &$setCourier, &$setType, &$typeDist, &$sample, $apply) {
                foreach ($rows as $row) {
                    $scanned++;

                    $courierId = $this->mapper->resolveCourierId($row->shipping_provider);
                    $type = $this->mapper->resolveShipmentType((string) $row->shipping_provider) ?: null;

                    $update = [];
                    if ($courierId && $courierId !== $row->courier_id) {
                        $update['courier_id'] = $courierId;
                        $setCourier++;
                    }
                    if ($type && $type !== $row->resolved_shipment_type) {
                        $update['resolved_shipment_type'] = $type;
                        $setType++;
                    }
                    if ($type) {
                        $typeDist[$type] = ($typeDist[$type] ?? 0) + 1;
                    }

                    if (! empty($update)) {
                        if (count($sample) < 15) {
                            $sample[] = sprintf(
                                '  %-42s -> courier_id:%s  tipe:%s',
                                mb_strimwidth((string) $row->shipping_provider, 0, 42),
                                $update['courier_id'] ?? '(tetap)',
                                $update['resolved_shipment_type'] ?? '(tetap)',
                            );
                        }
                        if ($apply) {
                            DB::table('sales_orders')->where('id', $row->id)->update($update);
                        }
                    }
                }
            });

        $this->info(sprintf(
            '%s Dipindai: %d | set courier_id: %d | set resolved_shipment_type: %d',
            $apply ? 'APPLY:' : 'DRY-RUN:',
            $scanned, $setCourier, $setType,
        ));

        if (! empty($typeDist)) {
            ksort($typeDist);
            $this->line('Sebaran tipe kirim (dari nama kurir):');
            foreach ($typeDist as $t => $n) {
                $this->line("  {$t}: {$n}");
            }
        }

        if (! empty($sample)) {
            $this->line('Contoh perubahan:');
            foreach ($sample as $s) {
                $this->line($s);
            }
        }

        if (! $apply) {
            $this->warn('');
            $this->warn('Ini dry-run — belum ada perubahan disimpan. Jalankan ulang dengan --apply untuk eksekusi.');
        } else {
            $this->info('Selesai. courier_id & resolved_shipment_type pesanan lama terisi.');
        }

        return self::SUCCESS;
    }
}
