<?php

namespace Modules\Outbound\Console\Commands;

use Illuminate\Console\Command;
use Modules\Outbound\Jobs\RefreshInstantTrackingJob;

class SweepInstantTrackingCommand extends Command
{
    protected $signature = 'outbound:sweep-instant-tracking {--shipment-id= : Specific shipment ID to refresh}';

    protected $description = 'Sweep active instant courier shipments and poll live driver status from channels';

    public function handle(): int
    {
        $shipmentId = $this->option('shipment-id');

        $this->info('Memulai sweep live tracking kurir instan...');

        RefreshInstantTrackingJob::dispatch($shipmentId ? (string) $shipmentId : null);

        $this->info('Job RefreshInstantTrackingJob berhasil di-dispatch.');

        return self::SUCCESS;
    }
}
