<?php

namespace Modules\Sales\Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Sales\Jobs\PrepareShopeeShippingLabelJob;
use Modules\Sales\Models\BulkShippingLabelItem;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\BulkShippingLabelService;
use Modules\Sales\Services\SalesOrderService;
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
        $this->user = User::factory()->create();
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

    public function test_shopee_label_yang_sudah_terunduh_harus_jadi_done(): void
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
            BulkShippingLabelItem::STATUS_DONE,
            $item->status,
            'Label Shopee sudah terunduh tapi item tidak DONE (status: '.$item->status.').',
        );
    }

    public function test_tiktok_label_url_harus_jadi_done(): void
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
            BulkShippingLabelItem::STATUS_DONE,
            $item->status,
            'Label TikTok tersedia di key "url" tapi item tidak DONE '
                .'(status: '.$item->status.', alasan: '.($item->reason ?? '-').').',
        );
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

        $first = SalesOrder::factory()->create([
            'source' => 'shopee',
            'channel_shop_id' => 'SHOP-BULK-DOCUMENTS',
            'channel_order_no' => 'ORDER-SHOPEE-1',
            'tracking_number' => 'SPX-1',
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
                return count($rows) === 2
                    && collect($rows)->pluck('order_sn')->sort()->values()->all() === ['ORDER-SHOPEE-1', 'ORDER-SHOPEE-2'];
            }))
            ->andReturn([
                'results' => [
                    'ORDER-SHOPEE-1|' => ['accepted' => true, 'response' => ['order_sn' => 'ORDER-SHOPEE-1']],
                    'ORDER-SHOPEE-2|' => ['accepted' => true, 'response' => ['order_sn' => 'ORDER-SHOPEE-2']],
                ],
            ]);
        $shopee->shouldReceive('getShippingDocumentResultsMass')
            ->once()
            ->andReturn([
                'results' => [
                    'ORDER-SHOPEE-1|' => ['ready' => false, 'status' => 'PROCESSING'],
                    'ORDER-SHOPEE-2|' => ['ready' => false, 'status' => 'PROCESSING'],
                ],
            ]);
        $this->app->instance(ShopeeOrderService::class, $shopee);

        app(BulkShippingLabelService::class)->processQueuedBatch($batch);

        $this->assertSame('preparing', $first->refresh()->shipping_label_status);
        $this->assertSame('preparing', $second->refresh()->shipping_label_status);
        Queue::assertPushed(PrepareShopeeShippingLabelJob::class, 2);
    }

    public function test_lazada_label_url_harus_jadi_done(): void
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
            BulkShippingLabelItem::STATUS_DONE,
            $item->status,
            'Kontrol Lazada ikut gagal — periksa infrastruktur test, bukan kontrak.',
        );
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
            BulkShippingLabelItem::STATUS_DONE,
            $item->status,
            'SPX Sameday punya resi normal — labelnya harus tetap dicetak, bukan dilewati.',
        );
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
