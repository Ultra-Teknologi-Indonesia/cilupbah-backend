<?php

declare(strict_types=1);

namespace Modules\Sales\Console\Commands;

use Illuminate\Console\Command;
use Modules\Sales\Services\ShippingLabelCacheService;

final class ReconcileShippingLabelCache extends Command
{
    protected $signature = 'shipping-labels:reconcile-cache {--limit=100} {--status : Read-only archive backlog summary}';

    protected $description = 'Recover pending label archives and prune only verified, retained local copies.';

    public function handle(ShippingLabelCacheService $service): int
    {
        if ($this->option('status')) {
            $this->line(json_encode($service->health(), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        $this->line(json_encode($service->reconcile(max(1, min(500, (int) $this->option('limit')))), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
