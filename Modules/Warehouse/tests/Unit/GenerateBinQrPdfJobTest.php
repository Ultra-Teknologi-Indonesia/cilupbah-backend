<?php

namespace Modules\Warehouse\Tests\Unit;

use Modules\Inventory\Jobs\AutoDetectStockReplenishmentJob;
use Modules\Inventory\Jobs\RefreshStockReplenishmentJob;
use Modules\Outbound\Jobs\RefreshInstantTrackingJob;
use Modules\Product\Jobs\ConfirmProductImportJob;
use Modules\Product\Jobs\PreviewProductImportJob;
use Modules\Sales\Jobs\ProcessSalesOrderImportJob;
use Modules\Warehouse\Jobs\GenerateBinQrPdfJob;
use Modules\Webhook\Jobs\DispatchWebhookEventJob;
use Modules\Webhook\Jobs\SendWebhookJob;
use Tests\TestCase;

class GenerateBinQrPdfJobTest extends TestCase
{
    public function test_routes_qr_pdf_generation_to_the_dedicated_queue(): void
    {
        $job = new GenerateBinQrPdfJob('qr-job-id');

        $this->assertSame(
            config('queue.routing.qr_labels.connection', 'redis-long'),
            $job->connection,
        );
        $this->assertSame(
            config('queue.routing.qr_labels.queue', 'qr-labels'),
            $job->queue,
        );
    }

    public function test_known_default_queue_workloads_are_routed_explicitly(): void
    {
        $jobs = [
            new AutoDetectStockReplenishmentJob,
            new RefreshStockReplenishmentJob,
            new RefreshInstantTrackingJob,
            new PreviewProductImportJob('product-import-id'),
            new ConfirmProductImportJob('product-import-id'),
            new ProcessSalesOrderImportJob('sales-import-id'),
            new DispatchWebhookEventJob('order.created', [], 'event-id'),
            new SendWebhookJob('delivery-id'),
        ];

        $this->assertSame(
            config('queue.names.stock_default', 'stock-default'),
            $jobs[0]->queue,
        );
        $this->assertSame(
            config('queue.names.stock_default', 'stock-default'),
            $jobs[1]->queue,
        );
        $this->assertSame(
            config('queue.names.tracking', 'tracking'),
            $jobs[2]->queue,
        );
        $this->assertSame(
            config('queue.names.imports', 'product'),
            $jobs[3]->queue,
        );
        $this->assertSame(
            config('queue.names.imports', 'product'),
            $jobs[4]->queue,
        );
        $this->assertSame(
            config('queue.names.sales', 'orders'),
            $jobs[5]->queue,
        );
        $this->assertSame(config('webhook.queue', 'webhooks'), $jobs[6]->queue);
        $this->assertSame(config('webhook.queue', 'webhooks'), $jobs[7]->queue);
    }
}
