<?php

namespace Modules\Sales\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Modules\Channel\Services\ChannelSyncSettingService;
use Modules\Sales\Jobs\RequestChannelAwbJob;
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
        $this->seed(\Database\Seeders\RoleSeeder::class);
        Storage::fake('documents');
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
            RequestChannelAwbJob::class,
            fn ($job) => $job->orderId === $order->id,
        );
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

    public function test_kurir_instan_dikenali_dari_shipping_provider_bukan_courier_name(): void
    {
        Queue::fake();

        $order = $this->orderWithoutAwb([
            'courier_name' => null,
            'shipping_provider' => 'GrabExpress Instant',
        ]);
        $batch = $this->createBatchFor($order);

        $this->assertSame(
            BulkShippingLabelItem::STATUS_SKIPPED_INSTANT,
            $this->itemOf($batch)->status,
            'Mapper channel tidak pernah mengisi courier_name, hanya shipping_provider. '
                .'Kalau deteksi kurir instan cuma membaca courier_name, di produksi tidak akan pernah cocok.',
        );

        Queue::assertNotPushed(RequestChannelAwbJob::class);
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
    }

    public function test_sinkronisasi_channel_mati_ditandai_gagal_bukan_menggantung(): void
    {
        Queue::fake();

        app(ChannelSyncSettingService::class)->setEnabled(false);

        $order = $this->orderWithoutAwb();
        $batch = $this->createBatchFor($order);
        $item = $this->itemOf($batch);

        $this->assertSame(
            BulkShippingLabelItem::STATUS_FAILED,
            $item->status,
            'Kalau sinkronisasi mati, item harus gagal dengan alasan jelas — bukan menunggu selamanya.',
        );
        $this->assertSame(BulkShippingLabelItem::REASON_CHANNEL_SYNC_PAUSED, $item->reason);

        Queue::assertNotPushed(RequestChannelAwbJob::class);
    }

    public function test_resi_yang_datang_memindahkan_item_dari_menunggu_ke_berhasil(): void
    {
        Queue::fake();

        $order = $this->orderWithoutAwb();
        $batch = $this->createBatchFor($order);

        $this->assertSame(
            BulkShippingLabelItem::STATUS_WAITING_AWB,
            $this->itemOf($batch)->status,
        );

        $order->update(['tracking_number' => 'JP1234567890']);

        $labelService = Mockery::mock(SalesOrderService::class);
        $labelService->shouldReceive('getShippingLabel')->andReturn([
            'type' => 'base64',
            'content_type' => 'application/pdf',
            'document_base64' => base64_encode('%PDF-1.4 LABEL SETELAH RESI TERBIT'),
            'source' => 'shopee',
        ]);

        (new BulkShippingLabelService($labelService))->onOrderAwbReady($order->id);

        $this->assertSame(
            BulkShippingLabelItem::STATUS_DONE,
            $this->itemOf($batch)->status,
            'Begitu resi terbit, item harus lanjut sendiri tanpa operator menekan apa pun.',
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

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
