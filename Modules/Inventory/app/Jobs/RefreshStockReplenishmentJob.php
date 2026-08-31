<?php

namespace Modules\Inventory\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Inventory\Services\StockReplenishmentService;

class RefreshStockReplenishmentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 60;

    public function __construct(public readonly ?string $locationId = null) {}

    public function uniqueId(): string
    {
        return 'stock-replenishment:'.($this->locationId ?? 'all');
    }

    public function handle(StockReplenishmentService $service): void
    {
        $service->reconcileAutoBatch();
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Stock replenishment reconciliation job failed', [
            'location_id' => $this->locationId,
            'error' => $exception->getMessage(),
        ]);
    }
}
