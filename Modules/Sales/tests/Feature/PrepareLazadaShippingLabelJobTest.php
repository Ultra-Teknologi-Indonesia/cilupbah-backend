<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Channel\Exceptions\ChannelLabelUnsupportedException;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Sales\Jobs\ArchiveShippingLabelCacheJob;
use Modules\Sales\Jobs\PrepareLazadaShippingLabelJob;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\SalesOrderService;
use setasign\Fpdi\Fpdi;
use Tests\TestCase;

class PrepareLazadaShippingLabelJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(array $overrides = []): SalesOrder
    {
        return SalesOrder::factory()->create(array_merge([
            'source' => 'lazada',
            'channel_shop_id' => 'SELLER-1',
            'channel_order_no' => 'LZD-ORDER-1',
            'tracking_number' => 'AWB999',
        ], $overrides));
    }

    public function test_marks_ready_and_caches_document_when_available(): void
    {
        $order = $this->makeOrder();

        $this->mock(LazadaOrderService::class, function ($m) {
            $m->shouldReceive('resolvePackageIds')->once()->with('SELLER-1', 'LZD-ORDER-1')
                ->andReturn(['FP-1']);
            $m->shouldReceive('getPackageDocument')->once()->with('SELLER-1', ['FP-1'], 'PDF')
                ->andReturn(['file' => 'JVBERi0xLjQ=', 'pdf_url' => null, 'doc_type' => 'PDF']);
        });

        (new PrepareLazadaShippingLabelJob($order->id))->handle(app(LazadaOrderService::class));

        $order->refresh();
        $this->assertSame('ready', $order->shipping_label_status);
        $this->assertSame('PDF', $order->shipping_label_doc_type);
        $this->assertNotNull($order->shipping_label_prepared_at);
        $this->assertSame('JVBERi0xLjQ=', $order->shipping_label_raw_data['document']['file']);
    }

    public function test_marks_not_ready_and_retries_when_document_absent(): void
    {
        Queue::fake();
        $order = $this->makeOrder();

        $this->mock(LazadaOrderService::class, function ($m) {
            $m->shouldReceive('resolvePackageIds')->once()->andReturn(['FP-1']);
            $m->shouldReceive('getPackageDocument')->once()->andReturn([]);
        });

        (new PrepareLazadaShippingLabelJob($order->id, 0))->handle(app(LazadaOrderService::class));

        $this->assertSame('not_ready', $order->refresh()->shipping_label_status);
        Queue::assertPushed(PrepareLazadaShippingLabelJob::class);
    }

    public function test_multi_batch_document_is_staged_locally_without_base64_in_order_metadata(): void
    {
        Queue::fake();
        Storage::fake('documents');
        Storage::fake('print_spool');
        $ids = array_map('strval', range(1, 21));
        $order = $this->makeOrder(['channel_package_ids' => $ids]);
        $pdf = new Fpdi;
        $pdf->AddPage();
        $bytes = $pdf->Output('S');
        $this->mock(LazadaOrderService::class, function ($mock) use ($ids, $bytes) {
            $mock->shouldReceive('getPackageDocument')->once()->with('SELLER-1', $ids, 'PDF')
                ->andReturn(['file' => base64_encode($bytes), 'pdf_url' => null, 'doc_type' => 'PDF', 'package_ids' => $ids]);
        });
        (new PrepareLazadaShippingLabelJob($order->id))->handle(app(LazadaOrderService::class));
        $order->refresh();
        $this->assertSame('ready', $order->shipping_label_status);
        $this->assertArrayNotHasKey('file', $order->shipping_label_raw_data['document']);
        $this->assertSame($bytes, app(SalesOrderService::class)->cachedShippingLabelBytes($order));
        Queue::assertPushed(ArchiveShippingLabelCacheJob::class);
    }

    public function test_marks_self_design_required_for_sof_dbs_order(): void
    {
        $order = $this->makeOrder();

        $this->mock(LazadaOrderService::class, function ($m) {
            $m->shouldReceive('resolvePackageIds')->once()->andReturn(['FP-1']);
            $m->shouldReceive('getPackageDocument')->once()
                ->andThrow(new ChannelLabelUnsupportedException('SOF/DBS'));
        });

        (new PrepareLazadaShippingLabelJob($order->id))->handle(app(LazadaOrderService::class));

        $this->assertSame('self_design_required', $order->refresh()->shipping_label_status);
    }

    public function test_recovers_a_stale_preparing_order(): void
    {
        $order = $this->makeOrder(['shipping_label_status' => 'preparing']);

        $this->mock(LazadaOrderService::class, function ($m) {
            $m->shouldReceive('resolvePackageIds')->once()->andReturn(['FP-1']);
            $m->shouldReceive('getPackageDocument')->once()->andReturn([
                'file' => 'JVBERi0xLjQ=',
                'pdf_url' => null,
                'doc_type' => 'PDF',
            ]);
        });

        (new PrepareLazadaShippingLabelJob($order->id))->handle(app(LazadaOrderService::class));

        $this->assertSame('ready', $order->refresh()->shipping_label_status);
    }
}
