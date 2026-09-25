<?php

namespace Modules\Sales\Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Sales\Jobs\PrepareBulkShopeeShippingLabelsJob;
use Modules\Sales\Jobs\PrepareShopeeShippingLabelJob;
use Modules\Sales\Jobs\ProcessBulkShippingLabelItemJob;
use Modules\Sales\Models\BulkShippingLabelItem;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\BulkShippingLabelService;
use Modules\Sales\Services\SalesOrderService;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class BulkShippingLabelContractTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('documents');
        Storage::fake('print_spool');
        $this->user = User::factory()->create();
    }

    public function test_identical_active_request_reuses_batch_but_changed_options_do_not(): void
    {
        Queue::fake();
        [$batch, $order] = $this->seedBatch('lazada');
        $service = app(BulkShippingLabelService::class);
        $same = $service->createBatch($this->user, [$order->id], $batch->per_channel_opts);
        $this->assertSame($batch->id, $same->id);
        $different = $service->createBatch($this->user, [$order->id], ['document_size' => BulkShippingLabelService::SIZE_100X150]);
        $this->assertNotSame($batch->id, $different->id);
        $otherUser = $service->createBatch(User::factory()->create(), [$order->id], $batch->per_channel_opts);
        $this->assertNotSame($batch->id, $otherUser->id);
    }

    public function test_rejoining_active_request_does_not_reset_an_in_progress_download(): void
    {
        Queue::fake();
        [$batch, $order] = $this->seedBatch('lazada');
        $item = $batch->items()->firstOrFail();
        $item->update(['status' => BulkShippingLabelItem::STATUS_DOWNLOADING]);
        $same = app(BulkShippingLabelService::class)->createBatch($this->user, [$order->id], $batch->per_channel_opts);
        $this->assertSame($batch->id, $same->id);
        $this->assertSame(BulkShippingLabelItem::STATUS_DOWNLOADING, $item->fresh()->status);
    }

    public function test_changed_warehouse_scope_cannot_reuse_a_previously_authorized_batch(): void
    {
        Queue::fake();
        $firstLocation = Location::factory()->create();
        $otherLocation = Location::factory()->create();
        $this->user->syncLocations([$firstLocation->id]);
        $this->actingAs($this->user);
        $order = SalesOrder::factory()->create(['source' => 'lazada', 'tracking_number' => 'AWB-SCOPE', 'location_id' => $firstLocation->id]);
        $service = app(BulkShippingLabelService::class);
        $first = $service->createBatch($this->user, [$order->id], []);
        $this->user->syncLocations([$otherLocation->id]);
        $second = $service->createBatch($this->user, [$order->id], []);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(BulkShippingLabelItem::STATUS_FAILED, $second->items()->firstOrFail()->status);
    }

    public function test_transient_job_exception_restores_pending_and_can_retry(): void
    {
        Queue::fake();
        [$batch, $order] = $this->seedBatch('lazada');
        $item = $batch->items()->firstOrFail();
        $calls = 0;
        $service = Mockery::mock(BulkShippingLabelService::class);
        $service->shouldReceive('processPendingItem')->andReturnUsing(function () use (&$calls): bool {
            if (++$calls === 1) {
                throw new \RuntimeException('Temporary connection failure');
            }

            return true;
        });
        $service->shouldReceive('tryFinalize')->once();
        try {
            (new ProcessBulkShippingLabelItemJob($batch->id, $item->id, $order->id, 'lazada'))->handle($service);
            $this->fail('Expected transient exception');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Temporary connection failure', $exception->getMessage());
        }
        $this->assertSame(BulkShippingLabelItem::STATUS_PENDING, $item->fresh()->status);
        (new ProcessBulkShippingLabelItemJob($batch->id, $item->id, $order->id, 'lazada'))->handle($service);
        $this->assertSame(2, $calls);
    }

    public function test_redelivered_job_reclaims_downloading_after_worker_died(): void
    {
        Queue::fake();
        [$batch, $order] = $this->seedBatch('lazada');
        $item = $batch->items()->firstOrFail();
        $item->update(['status' => BulkShippingLabelItem::STATUS_DOWNLOADING]);
        $service = Mockery::mock(BulkShippingLabelService::class);
        $service->shouldReceive('processPendingItem')->once()->andReturnTrue();
        $service->shouldReceive('tryFinalize')->once();
        (new ProcessBulkShippingLabelItemJob($batch->id, $item->id, $order->id, 'lazada'))->handle($service);
        $this->assertSame(BulkShippingLabelItem::STATUS_DOWNLOADING, $item->fresh()->status);
    }

    public function test_exception_after_completion_does_not_reopen_ready_item(): void
    {
        Queue::fake();
        [$batch, $order] = $this->seedBatch('lazada');
        $item = $batch->items()->firstOrFail();
        $service = Mockery::mock(BulkShippingLabelService::class);
        $service->shouldReceive('processPendingItem')->once()->andReturnUsing(function () use ($item): bool {
            $item->update(['status' => BulkShippingLabelItem::STATUS_READY]);
            throw new \RuntimeException('Notification unavailable');
        });
        try {
            (new ProcessBulkShippingLabelItemJob($batch->id, $item->id, $order->id, 'lazada'))->handle($service);
            $this->fail('Expected notification failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Notification unavailable', $exception->getMessage());
        }
        $this->assertSame(BulkShippingLabelItem::STATUS_READY, $item->fresh()->status);
        (new ProcessBulkShippingLabelItemJob($batch->id, $item->id, $order->id, 'lazada'))->handle($service);
    }

    private function fakeLabelService(array $returnShape): BulkShippingLabelService
    {
        $mock = Mockery::mock(SalesOrderService::class);
        $mock->shouldReceive('getShippingLabel')->andReturn($returnShape);
        $mock->shouldReceive('cacheShippingLabelBytes')->byDefault();
        $mock->shouldReceive('cachedFpdiShippingLabelBytes')->andReturnNull();
        $mock->shouldReceive('cacheFpdiShippingLabelBytes')->byDefault();

        return new BulkShippingLabelService($mock);
    }

    private function seedBatch(string $channel, string $courierName = 'JNE REG'): array
    {
        $order = SalesOrder::factory()->create([
            'source' => $channel,
            'tracking_number' => 'AWB-'.strtoupper($channel),
            'courier_name' => $courierName,
        ]);

        $svc = app(BulkShippingLabelService::class);
        $batch = $svc->createBatch($this->user, [$order->id], [
            'document_size' => BulkShippingLabelService::DEFAULT_SIZE,
        ]);

        return [$batch, $order];
    }

    public function test_ready_notification_during_preparation_dispatch_is_not_lost(): void
    {
        Queue::fake();
        [$batch, $order] = $this->seedBatch('shopee');
        $order->update(['shipping_label_status' => 'preparing']);
        $item = $batch->items()->firstOrFail();
        $service = app(BulkShippingLabelService::class);
        $originalBus = Bus::getFacadeRoot();
        $bus = Mockery::mock($originalBus);
        Bus::swap($bus);
        $bus->shouldReceive('dispatch')->andReturnUsing(fn ($job) => $originalBus->dispatch($job))->byDefault();
        $bus->shouldReceive('dispatch')
            ->with(Mockery::type(PrepareShopeeShippingLabelJob::class))
            ->once()->andReturnUsing(function () use ($order, $service): bool {
                $order->update(['shipping_label_status' => 'ready']);
                $service->onOrderLabelReady($order->id);

                return true;
            });

        $service->processPendingItem($item);

        $this->assertSame(BulkShippingLabelItem::STATUS_PENDING, $item->fresh()->status);
        Queue::assertPushed(ProcessBulkShippingLabelItemJob::class);
    }

    public function test_thermal_cache_hanya_dipakai_untuk_file_asal_dan_ukuran_yang_sama(): void
    {
        $order = SalesOrder::factory()->create();
        $service = app(SalesOrderService::class);
        $source = '%PDF-1.4 source-label';
        $thermal = '%PDF-1.4 thermal-100x120';

        $service->cacheThermalShippingLabelBytes(
            $order,
            $source,
            BulkShippingLabelService::SIZE_100X120,
            $thermal,
        );

        $this->assertSame(
            $thermal,
            $service->cachedThermalShippingLabelBytes(
                $order,
                $source,
                BulkShippingLabelService::SIZE_100X120,
            ),
        );
        $this->assertNull(
            $service->cachedThermalShippingLabelBytes(
                $order,
                $source,
                BulkShippingLabelService::SIZE_100X150,
            ),
        );
        $this->assertNull(
            $service->cachedThermalShippingLabelBytes(
                $order,
                '%PDF-1.4 changed-source-label',
                BulkShippingLabelService::SIZE_100X120,
            ),
        );
    }

    public function test_fpdi_compatibility_cache_hanya_dipakai_untuk_file_asal_yang_sama(): void
    {
        $order = SalesOrder::factory()->create();
        $service = app(SalesOrderService::class);
        $source = '%PDF-1.4 marketplace-label';
        $prepared = '%PDF-1.4 fpdi-compatible-label';

        $service->cacheFpdiShippingLabelBytes($order, $source, $prepared);

        $this->assertSame($prepared, $service->cachedFpdiShippingLabelBytes($order, $source));
        $this->assertNull($service->cachedFpdiShippingLabelBytes($order, '%PDF-1.4 new-label'));
    }

    public function test_shopee_label_yang_sudah_terunduh_siap_untuk_merge(): void
    {
        [$batch] = $this->seedBatch('shopee');

        $svc = $this->fakeLabelService([
            'type' => 'base64',
            'content_type' => 'application/pdf',
            'document_base64' => base64_encode('%PDF-1.4 SHOPEE LABEL'),
            'source' => 'shopee',
        ]);

        $svc->processPendingItems($batch, $batch->per_channel_opts);

        $item = BulkShippingLabelItem::where('batch_id', $batch->id)->firstOrFail();

        $this->assertSame(
            BulkShippingLabelItem::STATUS_READY,
            $item->status,
            'Label Shopee harus siap untuk proses merge.',
        );
        Storage::disk('print_spool')->assertExists($item->ready_pdf_path);
    }

    public function test_tiktok_label_url_siap_untuk_merge(): void
    {
        Http::fake(['*' => Http::response('%PDF-1.4 TIKTOK LABEL', 200)]);

        [$batch] = $this->seedBatch('tiktok');

        $svc = $this->fakeLabelService([
            'type' => 'url',
            'url' => 'https://tiktok.example/label.pdf',
            'source' => 'tiktok',
        ]);

        $svc->processPendingItems($batch, $batch->per_channel_opts);

        $item = BulkShippingLabelItem::where('batch_id', $batch->id)->firstOrFail();

        $this->assertSame(
            BulkShippingLabelItem::STATUS_READY,
            $item->status,
            'Label TikTok tersedia di key "url" tapi item belum READY '
                .'(status: '.$item->status.', alasan: '.($item->reason ?? '-').').',
        );
        Storage::disk('print_spool')->assertExists($item->ready_pdf_path);
    }

    public function test_tiktok_label_yang_sudah_dipersiapkan_tidak_fetch_ulang_ke_marketplace(): void
    {
        Http::fake();

        $order = SalesOrder::factory()->create([
            'source' => 'tiktok',
            'channel_shop_id' => 'SHOP-1',
            'channel_order_no' => 'TT-ORDER-1',
            'shipping_label_status' => 'ready',
            'shipping_label_raw_data' => [
                'channel' => 'tiktok',
                'documents' => [[
                    'package_id' => 'PKG-1',
                    'doc_url' => 'https://tts.example/label.pdf',
                ]],
            ],
        ]);

        $result = app(SalesOrderService::class)->getShippingLabel($order);

        $this->assertSame('https://tts.example/label.pdf', $result['url']);
        $this->assertTrue($result['cached']);
        Http::assertNothingSent();
    }

    public function test_tiktok_multi_package_keeps_all_cached_document_urls(): void
    {
        Http::fake();

        $order = SalesOrder::factory()->create([
            'source' => 'tiktok',
            'channel_shop_id' => 'SHOP-1',
            'channel_order_no' => 'TT-ORDER-MULTI',
            'shipping_label_status' => 'ready',
            'shipping_label_raw_data' => [
                'channel' => 'tiktok',
                'documents' => [
                    ['package_id' => 'PKG-1', 'doc_url' => 'https://tts.example/one.pdf'],
                    ['package_id' => 'PKG-2', 'doc_url' => 'https://tts.example/two.pdf'],
                ],
            ],
        ]);

        $result = app(SalesOrderService::class)->getShippingLabel($order);

        $this->assertSame('https://tts.example/one.pdf', $result['url']);
        $this->assertSame([
            'https://tts.example/one.pdf',
            'https://tts.example/two.pdf',
        ], $result['urls']);
        Http::assertNothingSent();
    }

    public function test_queued_shopee_batch_creates_and_checks_documents_in_bulk(): void
    {
        Queue::fake();
        config(['bulk-labels.async_shopee_preparation' => true]);

        $first = SalesOrder::factory()->create([
            'source' => 'shopee',
            'channel_shop_id' => 'SHOP-BULK-DOCUMENTS',
            'channel_order_no' => 'ORDER-SHOPEE-1',
            'tracking_number' => 'SPX-1',
            'channel_package_ids' => ['PKG-SHOPEE-1A', 'PKG-SHOPEE-1B'],
            'shipping_label_raw_data' => [
                'package_tracking_numbers' => [
                    'PKG-SHOPEE-1A' => 'SPX-1A',
                    'PKG-SHOPEE-1B' => 'SPX-1B',
                ],
            ],
        ]);
        $second = SalesOrder::factory()->create([
            'source' => 'shopee',
            'channel_shop_id' => 'SHOP-BULK-DOCUMENTS',
            'channel_order_no' => 'ORDER-SHOPEE-2',
            'tracking_number' => 'SPX-2',
        ]);
        $batch = app(BulkShippingLabelService::class)->createBatch($this->user, [
            $first->id,
            $second->id,
        ], []);

        $shopee = Mockery::mock(ShopeeOrderService::class);
        $shopee->shouldReceive('createShippingDocumentsMass')
            ->once()
            ->with('SHOP-BULK-DOCUMENTS', Mockery::on(function (array $rows): bool {
                return count($rows) === 3
                    && collect($rows)->pluck('package_number')->filter()->sort()->values()->all() === [
                        'PKG-SHOPEE-1A',
                        'PKG-SHOPEE-1B',
                    ]
                    && collect($rows)->pluck('order_sn')->filter()->sort()->values()->all() === [
                        'ORDER-SHOPEE-1',
                        'ORDER-SHOPEE-1',
                        'ORDER-SHOPEE-2',
                    ];
            }))
            ->andReturn([
                'results' => [
                    'ORDER-SHOPEE-1|PKG-SHOPEE-1A' => ['accepted' => true, 'response' => ['order_sn' => 'ORDER-SHOPEE-1']],
                    'ORDER-SHOPEE-1|PKG-SHOPEE-1B' => ['accepted' => true, 'response' => ['order_sn' => 'ORDER-SHOPEE-1']],
                    'ORDER-SHOPEE-2|' => ['accepted' => true, 'response' => ['order_sn' => 'ORDER-SHOPEE-2']],
                ],
            ]);
        $shopee->shouldReceive('getShippingDocumentResultsMass')
            ->once()
            ->andReturn([
                'results' => [
                    'ORDER-SHOPEE-1|PKG-SHOPEE-1A' => ['ready' => false, 'status' => 'PROCESSING'],
                    'ORDER-SHOPEE-1|PKG-SHOPEE-1B' => ['ready' => false, 'status' => 'PROCESSING'],
                    'ORDER-SHOPEE-2|' => ['ready' => false, 'status' => 'PROCESSING'],
                ],
            ]);
        $this->app->instance(ShopeeOrderService::class, $shopee);

        app(BulkShippingLabelService::class)->processQueuedBatch($batch);

        Queue::assertPushed(PrepareBulkShopeeShippingLabelsJob::class, 1);
        $preparation = Queue::pushed(PrepareBulkShopeeShippingLabelsJob::class)->first();
        $this->assertSame(config('queue.names.label_download_shopee'), $preparation->queue);
        $preparation->handle(app(BulkShippingLabelService::class));

        $this->assertSame('preparing', $first->refresh()->shipping_label_status);
        $this->assertSame('preparing', $second->refresh()->shipping_label_status);
        Queue::assertPushed(PrepareShopeeShippingLabelJob::class, 2);
    }

    public function test_lazada_label_url_siap_untuk_merge(): void
    {
        Http::fake(['*' => Http::response('%PDF-1.4 LAZADA LABEL', 200)]);

        [$batch] = $this->seedBatch('lazada');

        $svc = $this->fakeLabelService([
            'type' => 'url',
            'url' => 'https://lazada.example/label.pdf',
            'source' => 'lazada',
        ]);

        $svc->processPendingItems($batch, $batch->per_channel_opts);

        $item = BulkShippingLabelItem::where('batch_id', $batch->id)->firstOrFail();

        $this->assertSame(
            BulkShippingLabelItem::STATUS_READY,
            $item->status,
            'Kontrol Lazada ikut gagal — periksa infrastruktur test, bukan kontrak.',
        );
        Storage::disk('print_spool')->assertExists($item->ready_pdf_path);
    }

    public function test_spx_sameday_tetap_dicetak_labelnya(): void
    {
        [$batch] = $this->seedBatch('shopee', 'SPX Sameday');

        $svc = $this->fakeLabelService([
            'type' => 'base64',
            'content_type' => 'application/pdf',
            'document_base64' => base64_encode('%PDF-1.4 SPX SAMEDAY LABEL'),
            'source' => 'shopee',
        ]);

        $svc->processPendingItems($batch, $batch->per_channel_opts);

        $item = BulkShippingLabelItem::where('batch_id', $batch->id)->firstOrFail();

        $this->assertSame(
            BulkShippingLabelItem::STATUS_READY,
            $item->status,
            'SPX Sameday punya resi normal — labelnya harus tetap dicetak, bukan dilewati.',
        );
        Storage::disk('print_spool')->assertExists($item->ready_pdf_path);
    }

    public function test_kurir_instan_tetap_diproses_labelnya(): void
    {
        [$batch] = $this->seedBatch('shopee', 'GrabExpress Instant');

        $item = BulkShippingLabelItem::where('batch_id', $batch->id)->firstOrFail();

        $this->assertSame(
            BulkShippingLabelItem::STATUS_PENDING,
            $item->status,
            'Kurir instan seperti GrabExpress Instant tetap diproses pencetakan labelnya.',
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
