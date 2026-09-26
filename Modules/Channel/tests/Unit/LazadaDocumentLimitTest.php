<?php

declare(strict_types=1);

namespace Modules\Channel\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Sales\Exceptions\ShippingLabelPreparingException;
use Modules\Sales\Models\ShippingLabelCacheArtifact;
use Modules\Webhook\Support\WebhookUrlGuard;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Tests\TestCase;

final class LazadaDocumentLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('print_spool');
        Storage::fake('documents');
        Queue::fake();
        Http::preventStrayRequests();
        $this->freezeTime();
        config(['ratelimit.channel_api_per_second_by_channel.lazada' => 10000,
            'services.lazada.app_key' => 'test', 'services.lazada.app_secret' => 'test',
            'services.lazada.base_url' => 'https://api.lazada.co.id/rest']);
        $channel = Channel::create(['code' => 'lazada', 'name' => 'Lazada', 'is_active' => true]);
        ChannelShop::create(['channel_id' => $channel->id, 'shop_id' => 'shop', 'shop_name' => 'Test',
            'access_token' => 'test', 'refresh_token' => 'test', 'token_expires_at' => now()->addDays(7), 'is_active' => true]);
    }

    private function pdf(array $packages): string
    {
        $pdf = new Fpdi;
        foreach ($packages as $package) {
            $pdf->AddPage();
            $pdf->SetFont('Arial', '', 12);
            $pdf->Text(10, 20, 'PACKAGE-'.$package['package_id']);
        }

        return $pdf->Output('S');
    }

    public function test_41_packages_are_requested_as_20_20_1_and_all_pages_are_merged(): void
    {
        $seen = [];
        Http::fake(function ($request) use (&$seen) {
            $this->assertStringContainsString('/order/package/document/get', $request->url());
            $input = json_decode($request['getDocumentReq'], true);
            $seen[] = array_column($input['packages'], 'package_id');

            return Http::response(['code' => '0', 'result' => ['success' => true, 'data' => [
                'file' => base64_encode($this->pdf($input['packages'])), 'doc_type' => 'PDF',
            ]]]);
        });
        $ids = array_map('strval', range(1, 41));
        $result = app(LazadaOrderService::class)->getPackageDocument('shop', [...$ids, '1', '']);
        $this->assertSame([20, 20, 1], array_map('count', $seen));
        $this->assertSame($ids, array_merge(...$seen));
        $this->assertSame($ids, $result['package_ids']);
        $this->assertSame(41, (new Fpdi)->setSourceFile(StreamReader::createByString(base64_decode($result['file']))));
        Http::assertSentCount(3);
    }

    public function test_pending_chunk_never_returns_partial_pdf_and_retry_reuses_completed_chunk(): void
    {
        $pending = true;
        $sizes = [];
        Http::fake(function ($request) use (&$pending, &$sizes) {
            $packages = json_decode($request['getDocumentReq'], true)['packages'];
            $sizes[] = count($packages);
            if ($pending && count($packages) === 1) {
                return Http::response(['code' => '0', 'result' => ['data' => []]]);
            }

            return Http::response(['code' => '0', 'result' => ['data' => ['file' => base64_encode($this->pdf($packages)), 'doc_type' => 'PDF']]]);
        });
        $ids = array_map('strval', range(1, 21));
        try {
            app(LazadaOrderService::class)->getPackageDocument('shop', $ids);
            $this->fail('Partial document was incorrectly returned.');
        } catch (ShippingLabelPreparingException $e) {
            $this->assertStringContainsString('seluruh paket', $e->getMessage());
        }
        $this->assertSame(1, ShippingLabelCacheArtifact::count());
        $pending = false;
        $result = app(LazadaOrderService::class)->getPackageDocument('shop', $ids);
        $this->assertSame([20, 1, 1], $sizes);
        $this->assertSame(21, (new Fpdi)->setSourceFile(StreamReader::createByString(base64_decode($result['file']))));
    }

    public function test_invalid_pdf_is_not_cached_or_merged(): void
    {
        Http::fake(['*' => Http::response(['code' => '0', 'result' => ['data' => ['file' => base64_encode('broken'), 'doc_type' => 'PDF']]])]);
        try {
            app(LazadaOrderService::class)->getPackageDocument('shop', array_map('strval', range(1, 21)));
            $this->fail('Broken PDF accepted.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('bukan PDF', $e->getMessage());
        }
        $this->assertSame(0, ShippingLabelCacheArtifact::count());
    }

    public function test_resource_limit_rejects_instead_of_silently_truncating(): void
    {
        try {
            app(LazadaOrderService::class)->getPackageDocument('shop', array_map('strval', range(1, 501)));
            $this->fail('Over-limit request accepted.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('batas aman', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_mixed_base64_and_url_chunks_are_both_included(): void
    {
        $this->mock(WebhookUrlGuard::class, fn ($mock) => $mock->shouldReceive('isSafe')->with('https://cdn.example/label.pdf')->once()->andReturn(true));
        Http::fake(function ($request) {
            if ($request->url() === 'https://cdn.example/label.pdf') {
                return Http::response($this->pdf([['package_id' => '21']]));
            }
            $packages = json_decode($request['getDocumentReq'], true)['packages'];
            $data = count($packages) === 1 ? ['pdf_url' => 'https://cdn.example/label.pdf'] : ['file' => base64_encode($this->pdf($packages))];

            return Http::response(['code' => '0', 'result' => ['data' => $data + ['doc_type' => 'PDF']]]);
        });
        $result = app(LazadaOrderService::class)->getPackageDocument('shop', array_map('strval', range(1, 21)));
        $this->assertSame(21, (new Fpdi)->setSourceFile(StreamReader::createByString(base64_decode($result['file']))));
        Http::assertSentCount(3);
    }

    public function test_error_response_with_file_is_not_treated_as_complete(): void
    {
        Http::fake(['*' => Http::response(['code' => '0', 'result' => ['success' => false, 'error_code' => 'FAILED',
            'data' => ['file' => base64_encode($this->pdf([['package_id' => '1']]))],
        ]])]);
        $this->assertSame([], app(LazadaOrderService::class)->getPackageDocument('shop', ['1']));
    }

    public function test_page_limit_prevents_partial_output(): void
    {
        config(['bulk-labels.lazada_document_max_pages' => 1]);
        Http::fake(function ($request) {
            $packages = json_decode($request['getDocumentReq'], true)['packages'];

            return Http::response(['code' => '0', 'result' => ['data' => ['file' => base64_encode($this->pdf($packages)), 'doc_type' => 'PDF']]]);
        });
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Jumlah halaman');
        app(LazadaOrderService::class)->getPackageDocument('shop', array_map('strval', range(1, 21)));
    }
}
