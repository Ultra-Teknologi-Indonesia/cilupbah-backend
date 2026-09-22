<?php

declare(strict_types=1);

namespace Modules\Sales\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Sales\Jobs\PrepareShopeeShippingLabelBatchJob;
use Modules\Sales\Jobs\ProcessBulkShippingLabelItemJob;
use Modules\Sales\Models\BulkShippingLabelBatch;
use Modules\Sales\Models\BulkShippingLabelItem;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\BulkShippingLabelService;
use Tests\TestCase;

final class PrepareShopeeShippingLabelBatchJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_dispatches_one_mass_job_for_same_shop_courier_and_document_type(): void
    {
        Queue::fake();

        $batch = BulkShippingLabelBatch::create([
            'user_id' => User::factory()->create()->id,
            'status' => BulkShippingLabelBatch::STATUS_PROCESSING,
            'total_count' => 2,
            'done_count' => 0,
            'failed_count' => 0,
            'skipped_count' => 0,
        ]);

        $items = collect(['A', 'B'])->map(function (string $suffix) use ($batch): BulkShippingLabelItem {
            $order = SalesOrder::factory()->create([
                'source' => 'shopee',
                'channel_shop_id' => 'SHOP-BULK-DISPATCH',
                'channel_order_no' => 'ORDER-DISPATCH-'.$suffix,
                'tracking_number' => 'AWB-DISPATCH-'.$suffix,
                'shipping_provider' => 'SPX Standard',
            ]);

            return BulkShippingLabelItem::create([
                'batch_id' => $batch->id,
                'order_id' => $order->id,
                'channel' => 'shopee',
                'status' => BulkShippingLabelItem::STATUS_PENDING,
            ]);
        });

        $this->assertSame(1, app(BulkShippingLabelService::class)
            ->dispatchPendingItems($batch));

        Queue::assertPushed(PrepareShopeeShippingLabelBatchJob::class, function (PrepareShopeeShippingLabelBatchJob $job) use ($batch, $items): bool {
            return $job->batchId === $batch->id
                && $job->shopId === 'SHOP-BULK-DISPATCH'
                && $job->itemIds === $items->pluck('id')->map('strval')->all()
                && $job->documentType === 'THERMAL_AIR_WAYBILL';
        });
        Queue::assertNotPushed(ProcessBulkShippingLabelItemJob::class);
    }

    public function test_bulk_flow_uses_shopee_mass_document_download_and_deduplicates_pdf_path(): void
    {
        Queue::fake();
        Storage::fake('print_spool');

        $channel = Channel::create([
            'code' => 'shopee',
            'name' => 'Shopee',
            'is_active' => true,
        ]);
        ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => 'SHOP-BULK-LABEL',
            'shop_name' => 'Shopee Bulk Label',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'is_active' => true,
        ]);

        $batch = BulkShippingLabelBatch::create([
            'user_id' => User::factory()->create()->id,
            'status' => BulkShippingLabelBatch::STATUS_PROCESSING,
            'total_count' => 2,
            'done_count' => 0,
            'failed_count' => 0,
            'skipped_count' => 0,
        ]);

        $items = collect(['A', 'B'])->map(function (string $suffix) use ($batch): BulkShippingLabelItem {
            $order = SalesOrder::factory()->create([
                'source' => 'shopee',
                'channel_shop_id' => 'SHOP-BULK-LABEL',
                'channel_order_no' => 'ORDER-'.$suffix,
                'tracking_number' => 'AWB-'.$suffix,
                'shipping_provider' => 'SPX Standard',
                'channel_package_ids' => ['PKG-'.$suffix],
            ]);

            return BulkShippingLabelItem::create([
                'batch_id' => $batch->id,
                'order_id' => $order->id,
                'channel' => 'shopee',
                'status' => BulkShippingLabelItem::STATUS_PENDING,
            ]);
        });

        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $rows = $request->data()['order_list'] ?? [];

            return match ($path) {
                '/api/v2/logistics/create_shipping_document' => Http::response([
                    'error' => '',
                    'response' => ['result_list' => array_map(
                        static fn (array $row): array => [
                            'order_sn' => $row['order_sn'],
                            'package_number' => $row['package_number'] ?? null,
                        ],
                        $rows,
                    )],
                ]),
                '/api/v2/logistics/get_shipping_document_result' => Http::response([
                    'error' => '',
                    'response' => ['result_list' => array_map(
                        static fn (array $row): array => [
                            'order_sn' => $row['order_sn'],
                            'package_number' => $row['package_number'] ?? null,
                            'status' => 'READY',
                        ],
                        $rows,
                    )],
                ]),
                '/api/v2/logistics/download_shipping_document' => Http::response(
                    '%PDF-1.4 BULK LABEL',
                    200,
                    ['Content-Type' => 'application/pdf'],
                ),
                default => Http::response([], 404),
            };
        });

        (new PrepareShopeeShippingLabelBatchJob(
            (string) $batch->id,
            'SHOP-BULK-LABEL',
            $items->pluck('id')->map('strval')->all(),
        ))->handle(
            app(ShopeeOrderService::class),
            app(BulkShippingLabelService::class),
        );

        $items->each(function (BulkShippingLabelItem $item): void {
            $fresh = $item->fresh();
            $this->assertSame(BulkShippingLabelItem::STATUS_READY, $fresh->status);
            $this->assertNotNull($fresh->ready_pdf_path);
            $this->assertSame('%PDF-1.4 BULK LABEL', Storage::disk('print_spool')->get($fresh->ready_pdf_path));
        });

        $paths = $items->map(fn (BulkShippingLabelItem $item): ?string => $item->fresh()->ready_pdf_path)
            ->unique()
            ->values();
        $this->assertCount(1, $paths);

        $requests = Http::recorded()->map(static fn (array $record): Request => $record[0]);
        $downloadRequest = $requests->first(
            static fn (Request $request): bool => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/download_shipping_document'),
        );
        $this->assertNotNull($downloadRequest);
        $this->assertCount(2, $downloadRequest->data()['order_list']);
    }
}
