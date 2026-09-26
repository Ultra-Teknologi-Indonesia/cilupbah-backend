<?php

declare(strict_types=1);

namespace Modules\Sales\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Sales\Services\ShippingLabelCacheService;

final class ArchiveShippingLabelCacheJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 240;

    public int $uniqueFor = 900;

    public array $backoff = [30, 120, 300];

    public function __construct(public readonly string $artifactId)
    {
        $this->onConnection(config('queue.routing.label_archive.connection', 'redis-long'));
        $this->onQueue(config('queue.routing.label_archive.queue', 'label-archive'));
    }

    public function uniqueId(): string
    {
        return $this->artifactId;
    }

    public function handle(ShippingLabelCacheService $service): void
    {
        $service->archive($this->artifactId);
    }
}
