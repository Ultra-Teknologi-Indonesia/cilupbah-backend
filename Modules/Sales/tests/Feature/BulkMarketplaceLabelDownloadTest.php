<?php

declare(strict_types=1);

namespace Modules\Sales\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Modules\Channel\Exceptions\ShopeeApiException;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Support\ShopeeErrorCatalog;
use Modules\Sales\Exceptions\ShippingLabelPreparingException;
use Modules\Sales\Jobs\PrepareBulkShopeeShippingLabelsJob;
use Modules\Sales\Jobs\PrepareShopeeShippingLabelJob;
use Modules\Sales\Jobs\ProcessBulkShippingLabelItemJob;
use Modules\Sales\Jobs\RequestShopeeMassAwbJob;
use Modules\Sales\Models\BulkShippingLabelBatch;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\BulkMarketplaceLabelDownloadService;
use Modules\Sales\Services\BulkShippingLabelService;
use Modules\Sales\Services\MarketplaceLabelPdfSplitter;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Support\ChannelOperationLedger;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Tests\TestCase;

class BulkMarketplaceLabelDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('print_spool');
        Storage::fake('documents');
    }

    private function pdf(array $identities): string
    {
        $pdf = new Fpdi;
        foreach ($identities as $identity) {
            $pdf->AddPage('P', [100, 150]);
            $pdf->SetFont('Arial', '', 12);
            $pdf->Text(10, 20, $identity);
        }

        return $pdf->Output('S');
    }

    private function batch(int $count, string $channel = 'shopee'): BulkShippingLabelBatch
    {
        $batch = BulkShippingLabelBatch::create(['user_id' => User::factory()->create()->id,
            'status' => 'processing', 'total_count' => $count, 'started_at' => now()]);
        for ($i = 1; $i <= $count; $i++) {
            $order = SalesOrder::factory()->create(['source' => $channel, 'channel_shop_id' => 'test-shop',
                'channel_order_no' => 'ORDER-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'tracking_number' => 'TRACK-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'shipping_provider' => 'SPX', 'shipping_label_status' => 'ready', 'shipping_label_doc_type' => 'THERMAL_AIR_WAYBILL',
                'channel_package_ids' => ['PACKAGE-'.$i], 'channel_instant' => false]);
            $batch->items()->create(['order_id' => $order->id, 'channel' => $channel, 'status' => 'pending']);
        }

        return $batch;
    }

    public function test_splitter_maps_reordered_pages_by_identity_not_position_and_preserves_size(): void
    {
        $result = app(MarketplaceLabelPdfSplitter::class)->split($this->pdf(['ORDER-222222', 'ORDER-111111']), [
            'first' => ['ORDER-111111'], 'second' => ['ORDER-222222'],
        ]);
        $this->assertCount(2, $result);
        foreach ($result as $bytes) {
            $reader = new Fpdi;
            $this->assertSame(1, $reader->setSourceFile(StreamReader::createByString($bytes)));
            $size = $reader->getTemplateSize($reader->importPage(1));
            $this->assertEqualsWithDelta(100, $size['width'], 0.1);
            $this->assertEqualsWithDelta(150, $size['height'], 0.1);
        }
        $this->assertCount(1, app(MarketplaceLabelPdfSplitter::class)->split($result['first'], ['first' => ['ORDER-111111']]));
    }

    public function test_missing_or_ambiguous_pages_are_never_assigned_to_an_order(): void
    {
        foreach ([['ORDER-111111'], ['ORDER-111111 ORDER-222222', 'ORDER-111111']] as $pages) {
            try {
                app(MarketplaceLabelPdfSplitter::class)->split($this->pdf($pages), [
                    'first' => ['ORDER-111111'], 'second' => ['ORDER-222222'],
                ]);
                $this->fail('An incomplete or ambiguous PDF was accepted.');
            } catch (\RuntimeException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
    }

    public function test_shopee_150_ready_orders_use_three_downloads_and_retry_reuses_cached_files(): void
    {
        $batch = $this->batch(150);
        $mock = Mockery::mock(ShopeeOrderService::class);
        $mock->shouldReceive('downloadShippingDocumentsMass')->times(3)->andReturnUsing(function ($shop, $rows, $type) {
            $this->assertCount(50, $rows);
            $this->assertSame('test-shop', $shop);

            return ['error' => null, 'batches' => [['binary' => true,
                'content' => $this->pdf(array_reverse(array_column($rows, 'order_sn')))]]];
        });
        $this->app->instance(ShopeeOrderService::class, $mock);
        foreach ($batch->items()->pluck('id')->chunk(50) as $chunk) {
            app(BulkMarketplaceLabelDownloadService::class)->download($batch->id, $chunk->all(), 'shopee');
            app(BulkMarketplaceLabelDownloadService::class)->download($batch->id, $chunk->all(), 'shopee');
        }
        foreach ($batch->items()->with('order')->get() as $item) {
            $this->assertNotNull(app(SalesOrderService::class)->cachedShippingLabelBytes($item->order));
        }
        Queue::assertPushed(ProcessBulkShippingLabelItemJob::class, 150);
        Http::assertNothingSent();
    }

    public function test_shopee_mixed_couriers_are_never_sent_in_one_download(): void
    {
        $batch = $this->batch(4);
        $items = $batch->items()->with('order')->get();
        foreach ($items->take(2) as $item) {
            $item->order->update(['shipping_provider' => 'JNT']);
        }
        $mock = Mockery::mock(ShopeeOrderService::class);
        $mock->shouldReceive('downloadShippingDocumentsMass')->twice()->andReturnUsing(function ($shop, $rows) use ($items) {
            $couriers = $items->filter(fn ($item) => in_array($item->order->channel_order_no, array_column($rows, 'order_sn')))
                ->pluck('order.shipping_provider')->unique();
            $this->assertCount(1, $couriers);

            return ['batches' => [['binary' => true, 'content' => $this->pdf(array_column($rows, 'order_sn'))]]];
        });
        $this->app->instance(ShopeeOrderService::class, $mock);
        app(BulkMarketplaceLabelDownloadService::class)->download($batch->id, $items->modelKeys(), 'shopee');
    }

    public function test_unsafe_pdf_falls_back_without_attaching_partial_files(): void
    {
        $batch = $this->batch(2);
        $mock = Mockery::mock(ShopeeOrderService::class);
        $mock->shouldReceive('downloadShippingDocumentsMass')->once()->andReturn([
            'batches' => [['binary' => true, 'content' => $this->pdf(['UNKNOWN', 'UNKNOWN'])]],
        ]);
        $this->app->instance(ShopeeOrderService::class, $mock);
        app(BulkMarketplaceLabelDownloadService::class)->download($batch->id, $batch->items()->pluck('id')->all(), 'shopee');
        foreach ($batch->items()->with('order')->get() as $item) {
            $this->assertNull(data_get($item->order->shipping_label_raw_data, 'cache.path'));
        }
        Queue::assertPushed(ProcessBulkShippingLabelItemJob::class, 2);
    }

    public function test_lazada_groups_packages_across_twenty_different_orders(): void
    {
        $batch = $this->batch(20, 'lazada');
        $items = $batch->items()->with('order')->get();
        $mock = Mockery::mock(LazadaOrderService::class);
        $mock->shouldReceive('getPackageDocument')->once()->with('test-shop', Mockery::on(fn ($ids) => count($ids) === 20), 'PDF')
            ->andReturn(['file' => base64_encode($this->pdf($items->pluck('order.channel_order_no')->all())), 'doc_type' => 'PDF']);
        $this->app->instance(LazadaOrderService::class, $mock);
        app(BulkMarketplaceLabelDownloadService::class)->download($batch->id, $items->modelKeys(), 'lazada');
        foreach ($items as $item) {
            $this->assertNotNull(app(SalesOrderService::class)->cachedShippingLabelBytes($item->order->fresh()));
        }
    }

    public function test_mass_selection_never_requests_awb_for_instant_or_unknown_shipping_type(): void
    {
        foreach ([true, null] as $instant) {
            $order = SalesOrder::factory()->create(['source' => 'shopee', 'tracking_number' => null,
                'channel_shop_id' => 'test', 'channel_instant' => $instant]);
            $batch = app(BulkShippingLabelService::class)->createBatch(User::factory()->create(), [$order->id], []);
            $this->assertSame('skipped_instant', $batch->items()->sole()->status);
        }
        Queue::assertNotPushed(RequestShopeeMassAwbJob::class);
    }

    public function test_cancellation_during_download_never_attaches_the_cancelled_orders_label(): void
    {
        $batch = $this->batch(2);
        $items = $batch->items()->with('order')->get();
        $cancelled = $items->first()->order;
        $mock = Mockery::mock(ShopeeOrderService::class);
        $mock->shouldReceive('downloadShippingDocumentsMass')->once()->andReturnUsing(function ($shop, $rows) use ($cancelled) {
            $cancelled->update(['cancel_requested_at' => now()]);

            return ['batches' => [['binary' => true, 'content' => $this->pdf(array_column($rows, 'order_sn'))]]];
        });
        $this->app->instance(ShopeeOrderService::class, $mock);
        app(BulkMarketplaceLabelDownloadService::class)->download($batch->id, $items->modelKeys(), 'shopee');
        $this->assertNull(app(SalesOrderService::class)->cachedShippingLabelBytes($cancelled->fresh()));
        $this->assertNotNull(app(SalesOrderService::class)->cachedShippingLabelBytes($items->last()->order->fresh()));
        $cancelledItem = $items->first()->fresh();
        app(BulkShippingLabelService::class)->processPendingItem($cancelledItem);
        $this->assertSame('failed', $cancelledItem->fresh()->status);
    }

    public function test_split_order_downloads_every_package_without_recreating_the_document(): void
    {
        $batch = $this->batch(1);
        $order = $batch->items()->with('order')->sole()->order;
        $order->forceFill(['channel_package_ids' => ['PACKAGE-1', 'PACKAGE-2']])->saveQuietly();
        $mock = Mockery::mock(ShopeeOrderService::class);
        $mock->shouldReceive('downloadShippingDocumentsMass')->once()->with('test-shop', [
            ['order_sn' => $order->channel_order_no, 'package_number' => 'PACKAGE-1'],
            ['order_sn' => $order->channel_order_no, 'package_number' => 'PACKAGE-2'],
        ], 'THERMAL_AIR_WAYBILL')->andReturn(['batches' => [['binary' => true, 'content' => $this->pdf(['PACKAGE-1', 'PACKAGE-2'])]]]);
        $this->app->instance(ShopeeOrderService::class, $mock);
        $result = app(SalesOrderService::class)->getShippingLabel($order);
        $pdf = new Fpdi;
        $this->assertSame(2, $pdf->setSourceFile(StreamReader::createByString(base64_decode($result['document_base64']))));
        $this->assertSame(['PACKAGE-1', 'PACKAGE-2'], data_get($order->fresh()->shipping_label_raw_data, 'cache.package_ids'));
    }

    public function test_expired_document_reopens_only_document_preparation_not_shipping(): void
    {
        $order = $this->batch(1)->items()->with('order')->sole()->order;
        $document = ChannelOperationLedger::claim($order, 'create_shipping_label')['attempt'];
        ChannelOperationLedger::markSucceeded($document);
        $shipping = ChannelOperationLedger::claim($order, 'request_awb')['attempt'];
        ChannelOperationLedger::markSucceeded($shipping);
        $mock = Mockery::mock(ShopeeOrderService::class);
        $mock->shouldReceive('downloadShippingDocument')->once()->andThrow(new ShopeeApiException(
            'logistics_shipping_document_should_print_first', ShopeeErrorCatalog::RETRYABLE, 'Prepare again'));
        $this->app->instance(ShopeeOrderService::class, $mock);
        try {
            app(SalesOrderService::class)->getShippingLabel($order);
            $this->fail('Expired document must not be returned as ready.');
        } catch (ShippingLabelPreparingException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
        $this->assertSame('retryable', $document->fresh()->status);
        $this->assertSame('succeeded', $shipping->fresh()->status);
        $this->assertNull($order->fresh()->shipping_label_status);
        Queue::assertPushed(PrepareShopeeShippingLabelJob::class);
        Queue::assertNotPushed(RequestShopeeMassAwbJob::class);
    }

    public function test_bulk_failure_falls_back_without_marking_any_document_ready(): void
    {
        $batch = $this->batch(2);
        $mock = Mockery::mock(ShopeeOrderService::class);
        $mock->shouldReceive('downloadShippingDocumentsMass')->once()->andThrow(new \RuntimeException('Network unavailable'));
        $this->app->instance(ShopeeOrderService::class, $mock);
        app(BulkMarketplaceLabelDownloadService::class)->download($batch->id, $batch->items()->pluck('id')->all(), 'shopee');
        foreach ($batch->items()->with('order')->get() as $item) {
            $this->assertSame('pending', $item->status);
            $this->assertNull(app(SalesOrderService::class)->cachedShippingLabelBytes($item->order));
        }
        Queue::assertPushed(ProcessBulkShippingLabelItemJob::class, 2);
    }

    public function test_busy_order_does_not_block_preparation_of_the_rest_of_the_batch(): void
    {
        $batch = $this->batch(2);
        $items = $batch->items()->with('order')->get();
        foreach ($items as $item) {
            $item->order->update(['shipping_label_status' => null]);
        }
        $busy = $items->first();
        $free = $items->last();
        $lock = Cache::lock('shipping-label:prepare:'.$busy->order_id, 240);
        $this->assertTrue($lock->get());
        $key = $free->order->channel_order_no.'|'.$free->order->channel_package_ids[0];
        $mock = Mockery::mock(ShopeeOrderService::class);
        $mock->shouldReceive('createShippingDocumentsMass')->once()->with('test-shop', Mockery::on(fn ($rows) => count($rows) === 1))
            ->andReturn(['results' => [$key => ['accepted' => true]]]);
        $mock->shouldReceive('getShippingDocumentResultsMass')->once()->andReturn(['results' => [$key => ['ready' => true]]]);
        $this->app->instance(ShopeeOrderService::class, $mock);
        try {
            app(BulkShippingLabelService::class)->prepareShopeeChunk($batch->id, $items->modelKeys());
        } finally {
            $lock->release();
        }
        $this->assertSame('ready', $free->order->fresh()->shipping_label_status);
        $this->assertNull($busy->order->fresh()->shipping_label_status);
        Queue::assertPushed(PrepareBulkShopeeShippingLabelsJob::class,
            fn ($job) => $job->itemIds === [$busy->id]);
    }
}
