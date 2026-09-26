<?php

namespace Modules\Sales\Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Modules\Channel\Services\ChannelSyncSettingService;
use Modules\Outbound\Http\Controllers\OutboundFulfillmentController;
use Modules\Outbound\Services\OutboundFulfillmentService;
use Modules\Sales\Http\Controllers\BulkShippingLabelController;
use Modules\Sales\Jobs\FinalizeBulkShippingLabelBatchJob;
use Modules\Sales\Jobs\PrepareTikTokShippingLabelJob;
use Modules\Sales\Jobs\ProcessBulkShippingLabelItemJob;
use Modules\Sales\Jobs\ProcessBulkShippingLabelJob;
use Modules\Sales\Jobs\RequestChannelAwbJob;
use Modules\Sales\Jobs\RequestShopeeMassAwbJob;
use Modules\Sales\Jobs\RequestTikTokMassAwbJob;
use Modules\Sales\Jobs\WarmShippingLabelsJob;
use Modules\Sales\Models\BulkShippingLabelBatch;
use Modules\Sales\Models\BulkShippingLabelItem;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\BulkShippingLabelService;
use Modules\Sales\Services\SalesOrderService;
use Tests\TestCase;

class BulkLabelAwbPullTest extends TestCase
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

    private function orderWithoutAwb(array $overrides = []): SalesOrder
    {
        return SalesOrder::factory()->create(array_merge([
            'source' => 'shopee',
            'tracking_number' => null,
            'channel_order_no' => '2608138CUCUBUP',
            'channel_shop_id' => '123456789',
            'courier_name' => 'JNE REG',
        ], $overrides));
    }

    private function createBatchFor(SalesOrder $order)
    {
        return app(BulkShippingLabelService::class)->createBatch($this->user, [$order->id], [
            'document_size' => BulkShippingLabelService::DEFAULT_SIZE,
        ]);
    }

    private function itemOf($batch): BulkShippingLabelItem
    {
        return BulkShippingLabelItem::where('batch_id', $batch->id)->firstOrFail();
    }

    public function test_pesanan_tanpa_resi_menunggu_penarikan_bukan_langsung_gagal(): void
    {
        Queue::fake();

        $order = $this->orderWithoutAwb();
        $batch = $this->createBatchFor($order);

        $this->assertSame(
            BulkShippingLabelItem::STATUS_WAITING_AWB,
            $this->itemOf($batch)->status,
            'Pesanan tanpa resi harus menunggu penarikan dari marketplace, bukan divonis gagal.',
        );

        Queue::assertPushed(
            RequestShopeeMassAwbJob::class,
            fn (RequestShopeeMassAwbJob $job): bool => $job->orderIds === [$order->id],
        );
    }

    public function test_reused_batch_can_resume_awb_dispatch_without_creating_another_batch(): void
    {
        Queue::fake();
        $order = $this->orderWithoutAwb();
        $batch = $this->createBatchFor($order);
        $job = Queue::pushed(RequestShopeeMassAwbJob::class)->first();
        (new UniqueLock(app(Repository::class)))->release($job);
        Queue::fake();

        $same = $this->createBatchFor($order);

        $this->assertSame($batch->id, $same->id);
        Queue::assertPushed(RequestShopeeMassAwbJob::class, 1);
        $this->createBatchFor($order);
        Queue::assertPushed(RequestShopeeMassAwbJob::class, 1);
    }

    public function test_nomor_pesanan_marketplace_bukan_bukti_punya_resi(): void
    {
        Queue::fake();

        $order = $this->orderWithoutAwb([
            'channel_order_no' => '2608138CTDCWWF',
            'tracking_number' => null,
        ]);
        $batch = $this->createBatchFor($order);

        $this->assertSame(
            BulkShippingLabelItem::STATUS_WAITING_AWB,
            $this->itemOf($batch)->status,
            'channel_order_no itu nomor pesanan marketplace dan selalu terisi. '
                .'Kalau dianggap bukti punya resi, penarikan resi tidak akan pernah jalan di produksi.',
        );
    }

    public function test_shopee_orders_in_the_same_shop_use_one_mass_awb_job(): void
    {
        Queue::fake();

        $first = $this->orderWithoutAwb(['channel_order_no' => 'SHOPEE-MASS-1']);
        $second = $this->orderWithoutAwb(['channel_order_no' => 'SHOPEE-MASS-2']);

        app(BulkShippingLabelService::class)->createBatch(
            $this->user,
            [$first->id, $second->id],
            ['document_size' => BulkShippingLabelService::DEFAULT_SIZE],
        );

        Queue::assertPushed(RequestShopeeMassAwbJob::class, 1);
        Queue::assertPushed(
            RequestShopeeMassAwbJob::class,
            function (RequestShopeeMassAwbJob $job) use ($first, $second): bool {
                $actual = $job->orderIds;
                $expected = [(string) $first->id, (string) $second->id];
                sort($actual);
                sort($expected);

                return $actual === $expected;
            },
        );
        Queue::assertNotPushed(RequestChannelAwbJob::class);
    }

    public function test_tiktok_orders_in_the_same_shop_use_one_mass_awb_job(): void
    {
        Queue::fake();

        $first = $this->orderWithoutAwb([
            'source' => 'tiktok',
            'channel_order_no' => 'TIKTOK-MASS-1',
            'channel_shop_id' => 'TIKTOK-MASS-SHOP',
        ]);
        $second = $this->orderWithoutAwb([
            'source' => 'tiktok',
            'channel_order_no' => 'TIKTOK-MASS-2',
            'channel_shop_id' => 'TIKTOK-MASS-SHOP',
        ]);

        app(BulkShippingLabelService::class)->createBatch(
            $this->user,
            [$first->id, $second->id],
            ['document_size' => BulkShippingLabelService::DEFAULT_SIZE],
        );

        Queue::assertPushed(RequestTikTokMassAwbJob::class, 1);
        Queue::assertPushed(
            RequestTikTokMassAwbJob::class,
            function (RequestTikTokMassAwbJob $job) use ($first, $second): bool {
                $actual = $job->orderIds;
                $expected = [(string) $first->id, (string) $second->id];
                sort($actual);
                sort($expected);

                return $job->shopId === 'TIKTOK-MASS-SHOP' && $actual === $expected;
            },
        );
        Queue::assertNotPushed(RequestChannelAwbJob::class);
    }

    public function test_grabexpress_instant_tanpa_awb_masuk_ke_waiting_awb(): void
    {
        Queue::fake();

        $order = $this->orderWithoutAwb([
            'courier_name' => null,
            'shipping_provider' => 'GrabExpress Instant',
        ]);
        $batch = $this->createBatchFor($order);

        $this->assertSame(
            BulkShippingLabelItem::STATUS_WAITING_AWB,
            $this->itemOf($batch)->status,
            'Order kurir instan tanpa AWB tetap masuk ke status waiting_awb untuk ditarik resinya.',
        );

        Queue::assertPushed(RequestShopeeMassAwbJob::class);
    }

    public function test_lex_id_lazada_bukan_kurir_instan(): void
    {
        Queue::fake();

        $order = $this->orderWithoutAwb([
            'source' => 'lazada',
            'courier_name' => null,
            'shipping_provider' => 'LEX ID',
        ]);
        $batch = $this->createBatchFor($order);

        $this->assertSame(
            BulkShippingLabelItem::STATUS_WAITING_AWB,
            $this->itemOf($batch)->status,
            'LEX ID itu Lazada Express, kurir reguler Lazada. Kalau ikut daftar kurir instan, '
                .'label Lazada akan dilewati diam-diam dan tidak pernah tercetak.',
        );
    }

    public function test_kolom_kurir_terisi_walau_courier_name_kosong(): void
    {
        Queue::fake();

        $order = $this->orderWithoutAwb([
            'courier_name' => null,
            'shipping_provider' => 'SPX Hemat',
        ]);
        $batch = $this->createBatchFor($order);

        $req = Request::create('/');
        $req->setUserResolver(fn () => $this->user);

        $payload = app(BulkShippingLabelController::class)
            ->show($req, $batch)
            ->getData(true);

        $this->assertSame(
            'SPX Hemat',
            $payload['data']['items'][0]['courier_name'] ?? null,
            'Mapper channel tidak pernah mengisi courier_name, jadi kolom Kurir di layar '
                .'cetak resi akan kosong kalau tidak mundur ke shipping_provider.',
        );
    }

    public function test_pesanan_manual_tanpa_resi_tetap_gagal_tanpa_memanggil_channel(): void
    {
        Queue::fake();

        $order = $this->orderWithoutAwb([
            'source' => 'manual',
            'channel_shop_id' => null,
        ]);
        $batch = $this->createBatchFor($order);
        $item = $this->itemOf($batch);

        $this->assertSame(BulkShippingLabelItem::STATUS_FAILED, $item->status);
        $this->assertSame(
            BulkShippingLabelItem::REASON_CHANNEL_UNSUPPORTED,
            $item->reason,
            'Pesanan manual tidak punya marketplace untuk ditarik resinya.',
        );

        Queue::assertNotPushed(RequestChannelAwbJob::class);
        Queue::assertNotPushed(RequestShopeeMassAwbJob::class);
    }

    public function test_permintaan_berbeda_tidak_mewarisi_status_downloading_dari_batch_orphan(): void
    {
        Queue::fake();

        $order = $this->orderWithoutAwb([
            'tracking_number' => 'AWB-ORPHAN-001',
        ]);
        $firstBatch = $this->createBatchFor($order);
        BulkShippingLabelItem::where('batch_id', $firstBatch->id)->update([
            'status' => BulkShippingLabelItem::STATUS_DOWNLOADING,
        ]);

        $secondBatch = app(BulkShippingLabelService::class)->createBatch($this->user, [$order->id], [
            'document_size' => BulkShippingLabelService::SIZE_100X150,
        ]);
        $this->assertNotSame($firstBatch->id, $secondBatch->id);
        $secondItem = $this->itemOf($secondBatch);

        $this->assertSame(
            BulkShippingLabelItem::STATUS_PENDING,
            $secondItem->status,
            'Batch baru harus tetap pending; status downloading tidak boleh diwariskan dari batch lain.',
        );

        app(BulkShippingLabelService::class)->queueBatch($secondBatch);

        Queue::assertPushed(
            ProcessBulkShippingLabelJob::class,
            fn ($job) => $job->batchId === $secondBatch->id,
        );
    }

    public function test_batch_baru_menggunakan_kembali_label_ready_dari_batch_sebelumnya(): void
    {
        Queue::fake();

        $order = $this->orderWithoutAwb([
            'tracking_number' => 'AWB-REUSE-001',
        ]);
        $size = BulkShippingLabelService::DEFAULT_SIZE;
        $sourceBatch = BulkShippingLabelBatch::create([
            'user_id' => $this->user->id,
            'status' => BulkShippingLabelBatch::STATUS_READY,
            'per_channel_opts' => ['document_size' => $size],
            'total_count' => 1,
            'done_count' => 1,
            'failed_count' => 0,
            'skipped_count' => 0,
        ]);
        $sourcePath = "items/{$sourceBatch->id}/source-item/ready.pdf";
        Storage::disk('print_spool')->put($sourcePath, '%PDF-1.4 READY LABEL');
        BulkShippingLabelItem::create([
            'batch_id' => $sourceBatch->id,
            'order_id' => $order->id,
            'channel' => 'shopee',
            'status' => BulkShippingLabelItem::STATUS_READY,
            'ready_pdf_path' => $sourcePath,
        ]);

        $secondBatch = $this->createBatchFor($order);
        $secondItem = $this->itemOf($secondBatch);

        $this->assertSame(BulkShippingLabelItem::STATUS_READY, $secondItem->status);
        $this->assertNotSame($sourcePath, $secondItem->ready_pdf_path);
        $this->assertTrue(Storage::disk('print_spool')->exists($secondItem->ready_pdf_path));
        $this->assertSame(1, $secondBatch->refresh()->done_count);
        Queue::assertNotPushed(ProcessBulkShippingLabelItemJob::class);

        app(BulkShippingLabelService::class)->queueBatch($secondBatch);

        Queue::assertPushed(
            ProcessBulkShippingLabelJob::class,
            fn ($job) => $job->batchId === $secondBatch->id,
        );
    }

    public function test_batch_orphan_lama_dikembalikan_ke_antrean(): void
    {
        Queue::fake();

        $order = $this->orderWithoutAwb([
            'tracking_number' => 'AWB-ORPHAN-002',
        ]);
        $batch = $this->createBatchFor($order);
        $item = $this->itemOf($batch);
        $item->update(['status' => BulkShippingLabelItem::STATUS_DOWNLOADING]);

        $reset = app(BulkShippingLabelService::class)->requeueOrphanedBatch($batch);

        $this->assertSame(1, $reset);
        $this->assertSame(
            BulkShippingLabelItem::STATUS_PENDING,
            $item->refresh()->status,
        );
        Queue::assertPushed(
            ProcessBulkShippingLabelJob::class,
            fn ($job) => $job->batchId === $batch->id,
        );
    }

    public function test_sinkronisasi_global_mati_tidak_menghalangi_penarikan_awb_fulfillment(): void
    {
        Queue::fake();

        app(ChannelSyncSettingService::class)->setEnabled(false);

        $order = $this->orderWithoutAwb();
        $batch = $this->createBatchFor($order);
        $item = $this->itemOf($batch);

        $this->assertSame(
            BulkShippingLabelItem::STATUS_WAITING_AWB,
            $item->status,
            'Global sync hanya mematikan order/webhook/stok/katalog; fulfillment tetap boleh meminta AWB.',
        );
        $this->assertNull($item->reason);

        Queue::assertPushed(RequestShopeeMassAwbJob::class);
    }

    public function test_item_lama_yang_gagal_saat_global_pause_bisa_di_retry_tanpa_mengaktifkan_sync_global(): void
    {
        Queue::fake();

        app(ChannelSyncSettingService::class)->setEnabled(false);

        $order = $this->orderWithoutAwb();
        $batch = $this->createBatchFor($order);
        $item = $this->itemOf($batch);
        $item->update([
            'status' => BulkShippingLabelItem::STATUS_FAILED,
            'reason' => BulkShippingLabelItem::REASON_CHANNEL_SYNC_PAUSED,
        ]);
        $batch->update(['status' => BulkShippingLabelBatch::STATUS_FAILED]);
        $batch->recomputeCounts();

        app(BulkShippingLabelService::class)->retryFailed($this->user, $batch);

        $this->assertFalse(
            app(ChannelSyncSettingService::class)->isEnabled(),
            'Retry AWB tidak boleh mengaktifkan global sync.',
        );
        $this->assertSame(BulkShippingLabelItem::STATUS_WAITING_AWB, $item->refresh()->status);
        $this->assertNull($item->reason);
        Queue::assertPushed(RequestShopeeMassAwbJob::class);
    }

    public function test_resi_yang_datang_mengantrikan_item_untuk_diproses_idempotent(): void
    {
        Queue::fake();

        $order = $this->orderWithoutAwb();
        $batch = $this->createBatchFor($order);

        $this->assertSame(
            BulkShippingLabelItem::STATUS_WAITING_AWB,
            $this->itemOf($batch)->status,
        );

        $order->update(['tracking_number' => 'JP1234567890']);

        (new BulkShippingLabelService(Mockery::mock(SalesOrderService::class)))->onOrderAwbReady($order->id);

        $this->assertSame(BulkShippingLabelItem::STATUS_PENDING, $this->itemOf($batch)->status);
        Queue::assertPushed(ProcessBulkShippingLabelItemJob::class, function ($job) use ($batch): bool {
            return $job->batchId === $batch->id;
        });
    }

    public function test_batas_channel_melepas_worker_tanpa_menandai_label_gagal(): void
    {
        config()->set('queue.routing.labels.rate_limit_attempts', 1);
        config()->set('queue.routing.labels.rate_limit_decay_seconds', 60);

        $order = $this->orderWithoutAwb(['tracking_number' => 'AWB-RATE-LIMIT']);
        $batch = $this->createBatchFor($order);
        $item = $this->itemOf($batch);
        $item->update(['status' => BulkShippingLabelItem::STATUS_DOWNLOADING]);

        $key = 'bulk-label:shopee:'.$order->channel_shop_id;
        RateLimiter::clear($key);
        RateLimiter::attempt($key, 1, static fn (): bool => true, 60);

        $service = new BulkShippingLabelService(Mockery::mock(SalesOrderService::class));

        $this->assertFalse($service->processPendingItem($item->fresh()));
        $this->assertSame(
            BulkShippingLabelItem::STATUS_DOWNLOADING,
            $item->fresh()->status,
            'Job yang memanggil service akan mengembalikan item ini ke pending lalu mencoba lagi, bukan gagal.',
        );

        RateLimiter::clear($key);
    }

    public function test_siap_kirim_memanaskan_label_di_belakang_layar(): void
    {
        Queue::fake();

        $order = $this->orderWithoutAwb([
            'source' => 'tiktok',
            'tracking_number' => 'TT-WARM-001',
        ]);
        $fulfillment = Mockery::mock(OutboundFulfillmentService::class);
        $fulfillment->shouldReceive('readyToShip')
            ->once()
            ->with([$order->id])
            ->andReturn([[
                'order_id' => $order->id,
                'status' => 'success',
            ]]);

        $request = Request::create('/', 'POST', ['order_ids' => [$order->id]]);
        $request->setUserResolver(fn () => $this->user);

        (new OutboundFulfillmentController($fulfillment))->readyToShip($request);

        Queue::assertPushed(
            WarmShippingLabelsJob::class,
            fn (WarmShippingLabelsJob $job): bool => $job->orderIds === [$order->id],
        );

        Queue::fake();
        (new WarmShippingLabelsJob([$order->id]))->handle();
        Queue::assertPushed(
            PrepareTikTokShippingLabelJob::class,
            fn (PrepareTikTokShippingLabelJob $job): bool => $job->orderId === $order->id,
        );
    }

    public function test_siap_kirim_tanpa_resi_hanya_memantau_awb_tanpa_rts_ulang(): void
    {
        Queue::fake();

        $order = $this->orderWithoutAwb();
        $fulfillment = Mockery::mock(OutboundFulfillmentService::class);
        $fulfillment->shouldReceive('readyToShip')
            ->once()
            ->with([$order->id])
            ->andReturn([[
                'order_id' => $order->id,
                'status' => 'success',
            ]]);

        $request = Request::create('/', 'POST', ['order_ids' => [$order->id]]);
        $request->setUserResolver(fn () => $this->user);

        (new OutboundFulfillmentController($fulfillment))->readyToShip($request);

        Queue::assertPushed(WarmShippingLabelsJob::class);

        Queue::fake();
        (new WarmShippingLabelsJob([$order->id]))->handle();
        Queue::assertPushed(
            RequestChannelAwbJob::class,
            fn (RequestChannelAwbJob $job): bool => $job->orderId === $order->id
                && $job->requestReadyToShip === false,
        );
    }

    public function test_label_tiktok_yang_siap_mengirim_sse_dan_membangunkan_batch(): void
    {
        Queue::fake();

        $order = $this->orderWithoutAwb([
            'source' => 'tiktok',
            'tracking_number' => 'TT-SSE-001',
            'shipping_label_status' => 'ready',
        ]);
        $batch = $this->createBatchFor($order);
        $item = $this->itemOf($batch);
        $item->update(['status' => BulkShippingLabelItem::STATUS_WAITING_TIKTOK_PREP]);

        $redis = Mockery::mock();
        $redis->shouldReceive('xadd')
            ->once()
            ->withArgs(fn (string $key, string $id, array $fields): bool => $key === 'realtime:user:'.$this->user->id
                && $id === '*'
                && $fields['topic'] === 'bulk-label:'.$batch->id
                && $fields['event_type'] === 'bulk-label.progress'
                && data_get(json_decode($fields['payload'], true), 'status') === 'processing')
            ->andReturn('1-0');
        Redis::shouldReceive('connection')->once()->with('default')->andReturn($redis);

        app(BulkShippingLabelService::class)->onOrderLabelReady($order->id);

        $this->assertSame(BulkShippingLabelItem::STATUS_PENDING, $item->refresh()->status);
        Queue::assertPushed(
            ProcessBulkShippingLabelItemJob::class,
            fn (ProcessBulkShippingLabelItemJob $job): bool => $job->batchId === $batch->id,
        );
    }

    public function test_marketplace_tak_kunjung_menerbitkan_resi_bisa_dicoba_ulang(): void
    {
        Queue::fake();

        $order = $this->orderWithoutAwb();
        $batch = $this->createBatchFor($order);

        app(BulkShippingLabelService::class)->onOrderAwbGaveUp(
            $order->id,
            BulkShippingLabelItem::REASON_AWB_TIMEOUT,
        );

        $item = $this->itemOf($batch);

        $this->assertSame(BulkShippingLabelItem::STATUS_FAILED, $item->status);
        $this->assertTrue(
            $item->isRecoverable(),
            'Resi yang belum terbit harus bisa dicoba ulang dari layar cetak massal, bukan buntu.',
        );
    }

    public function test_finalisasi_pdf_dikirim_ke_worker_khusus_dan_tidak_memblokir_worker_awb(): void
    {
        Queue::fake();
        config()->set('bulk-labels.finalize_retry_delay_seconds', 0);

        $order = $this->orderWithoutAwb(['tracking_number' => 'AWB-FINALIZE-001']);
        $batch = $this->createBatchFor($order);
        $item = $this->itemOf($batch);
        $item->update([
            'status' => BulkShippingLabelItem::STATUS_READY,
            'ready_pdf_path' => 'items/missing-ready.pdf',
        ]);

        app(BulkShippingLabelService::class)->tryFinalize($batch->fresh());

        Queue::assertPushed(
            FinalizeBulkShippingLabelBatchJob::class,
            fn (FinalizeBulkShippingLabelBatchJob $job): bool => $job->batchId === $batch->id
                && $job->delay === null,
        );
    }

    public function test_file_ready_yang_hilang_menggagalkan_item_tanpa_lock_timeout_atau_loop(): void
    {
        $order = $this->orderWithoutAwb(['tracking_number' => 'AWB-FINALIZE-MISSING']);
        $batch = $this->createBatchFor($order);
        $item = $this->itemOf($batch);
        $item->update([
            'status' => BulkShippingLabelItem::STATUS_READY,
            'ready_pdf_path' => 'items/missing-ready.pdf',
        ]);

        app(BulkShippingLabelService::class)->finalizeInWorker($batch->id);

        $this->assertSame(
            BulkShippingLabelBatch::STATUS_FAILED,
            $batch->fresh()->status,
        );
        $this->assertSame(
            BulkShippingLabelItem::STATUS_FAILED,
            $item->fresh()->status,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
