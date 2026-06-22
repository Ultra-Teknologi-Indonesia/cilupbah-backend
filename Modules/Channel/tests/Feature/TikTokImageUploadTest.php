<?php

namespace Modules\Channel\Tests\Feature;

use App\Services\UploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Modules\Channel\Repositories\ChannelProductRepository;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Services\ChannelMediaResolver;
use Modules\Channel\Services\TikTokClient;
use Modules\Channel\Services\TikTokImageUploader;
use Modules\Channel\Services\TikTokProductMapper;
use Modules\Channel\Services\TikTokProductService;
use Tests\TestCase;

class TikTokImageUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolver_reads_bytes_from_media_uuid(): void
    {
        config(['filesystems.disks.media_test' => ['driver' => 'local', 'root' => storage_path('app/media_test')]]);
        config(['media-library.disk_name' => 'media_test']);
        Storage::fake('media_test');

        $media = app(UploadService::class)->store(
            UploadedFile::fake()->createWithContent('p.jpg', 'IMG-BYTES')
        );

        $this->assertSame('IMG-BYTES', app(ChannelMediaResolver::class)->bytes($media->uuid));
    }

    public function test_resolver_reads_bytes_from_media_disk(): void
    {
        Storage::fake('s3', ['url' => 'https://files.example.test']);
        config(['media-library.disk_name' => 's3']);

        Storage::disk('s3')->put('products/a.jpg', 'IMG-BYTES');
        $url = Storage::disk('s3')->url('products/a.jpg');

        $this->assertSame('IMG-BYTES', app(ChannelMediaResolver::class)->bytes($url));
    }

    public function test_resolver_falls_back_to_http_for_external_url(): void
    {
        Storage::fake('s3', ['url' => 'https://files.example.test']);
        config(['media-library.disk_name' => 's3']);

        Http::fake(['cdn.external.test/*' => Http::response('EXTERNAL-BYTES', 200)]);

        $this->assertSame(
            'EXTERNAL-BYTES',
            app(ChannelMediaResolver::class)->bytes('https://cdn.external.test/x.jpg')
        );
    }

    public function test_resolver_returns_null_when_unreachable(): void
    {
        Storage::fake('s3', ['url' => 'https://files.example.test']);
        config(['media-library.disk_name' => 's3']);

        Http::fake(['cdn.external.test/*' => Http::response('not found', 404)]);

        $this->assertNull(app(ChannelMediaResolver::class)->bytes('https://cdn.external.test/missing.jpg'));
    }

    public function test_uploader_uploads_bytes_and_returns_uris(): void
    {
        $client = Mockery::mock(TikTokClient::class);
        $client->shouldReceive('request')
            ->once()
            ->andReturn(['data' => ['uri' => 'tt-uri-1']]);

        $resolver = Mockery::mock(ChannelMediaResolver::class);
        $resolver->shouldReceive('bytes')->with('u1')->andReturn($this->pngBytes());

        $uploader = new TikTokImageUploader($client, $resolver);

        $this->assertSame(['tt-uri-1'], $uploader->uploadFromUrls(['u1'], 'access-token'));
    }

    public function test_uploader_skips_image_when_bytes_unavailable(): void
    {
        $client = Mockery::mock(TikTokClient::class);
        $client->shouldNotReceive('request');

        $resolver = Mockery::mock(ChannelMediaResolver::class);
        $resolver->shouldReceive('bytes')->andReturn(null);

        $uploader = new TikTokImageUploader($client, $resolver);

        $this->assertSame([], $uploader->uploadFromUrls(['u1'], 'access-token'));
    }

    private function pngBytes(int $w = 320, int $h = 320): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 10, 20, 30));
        ob_start();
        imagepng($img);
        $bytes = ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    private function gifBytes(int $w = 320, int $h = 320): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 10, 20, 30));
        ob_start();
        imagegif($img);
        $bytes = ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    public function test_http_fallback_sends_browser_user_agent(): void
    {
        Storage::fake('s3', ['url' => 'https://files.example.test']);
        config(['media-library.disk_name' => 's3']);
        Http::fake(['cdn.external.test/*' => Http::response($this->pngBytes(), 200)]);

        app(ChannelMediaResolver::class)->bytes('https://cdn.external.test/x.jpg');

        Http::assertSent(fn ($request) => str_contains($request->header('User-Agent')[0] ?? '', 'CilupbahBot'));
    }

    public function test_uploader_converts_unsupported_format_to_jpg(): void
    {
        $filename = null;
        $client = Mockery::mock(TikTokClient::class);
        $client->shouldReceive('request')->once()->andReturnUsing(function (...$args) use (&$filename) {
            $filename = $args[5]['data']['filename'] ?? null;

            return ['data' => ['uri' => 'tt-uri-gif']];
        });

        $resolver = Mockery::mock(ChannelMediaResolver::class);
        $resolver->shouldReceive('bytes')->andReturn($this->gifBytes());

        $result = (new TikTokImageUploader($client, $resolver))->upload(['g1'], 'token');

        $this->assertSame(['tt-uri-gif'], $result['uris']);
        $this->assertStringEndsWith('.jpg', (string) $filename);
    }

    public function test_upload_collects_error_for_invalid_image_bytes(): void
    {
        $client = Mockery::mock(TikTokClient::class);
        $client->shouldNotReceive('request');

        $resolver = Mockery::mock(ChannelMediaResolver::class);
        $resolver->shouldReceive('bytes')->andReturn('bukan-gambar');

        $result = (new TikTokImageUploader($client, $resolver))->upload(['x'], 'token');

        $this->assertSame([], $result['uris']);
        $this->assertCount(1, $result['errors']);
        $this->assertSame('format gambar tidak didukung', $result['errors'][0]['reason']);
    }

    public function test_push_product_throws_clear_error_when_all_images_fail(): void
    {
        $shop = (object) ['access_token' => 'tok', 'shop_cipher' => 'cipher', 'id' => 'shop-uuid', 'channel_id' => 'chan'];
        $product = (object) ['id' => 'p1', 'name' => 'Produk', 'category_id' => null];

        $client = Mockery::mock(TikTokClient::class);
        $client->shouldNotReceive('request'); 

        $shopRepo = Mockery::mock(ChannelShopRepository::class);
        $shopRepo->shouldReceive('findByShopId')->andReturn($shop);

        $productRepo = Mockery::mock(ChannelProductRepository::class);
        $productRepo->shouldReceive('findById')->andReturn($product);
        $productRepo->shouldReceive('getVariantsByProductId')->andReturn(collect([]));
        $productRepo->shouldReceive('getMediaByProductId')->andReturn([
            (object) ['media_type' => 'image', 'url' => 'https://cdn.external.test/a.jpg'],
        ]);

        $uploader = Mockery::mock(TikTokImageUploader::class);
        $uploader->shouldReceive('upload')->andReturn([
            'uris' => [],
            'errors' => [['url' => 'https://cdn.external.test/a.jpg', 'reason' => 'gambar tidak dapat diakses']],
        ]);

        $service = new TikTokProductService(
            $client,
            Mockery::mock(TikTokProductMapper::class),
            $shopRepo,
            $productRepo,
            $uploader
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Gagal memproses');
        $service->pushProduct('p1', 'SHOP');
    }

    public function test_upload_video_returns_id(): void
    {
        $client = Mockery::mock(TikTokClient::class);
        $client->shouldReceive('request')->once()->andReturn(['data' => ['id' => 'vid-1']]);

        $resolver = Mockery::mock(ChannelMediaResolver::class);
        $resolver->shouldReceive('bytes')->andReturn('VIDEO-BYTES');

        $this->assertSame('vid-1', (new TikTokImageUploader($client, $resolver))->uploadVideo('v.mp4', 'tok'));
    }

    public function test_push_product_sends_images_video_and_sku_img(): void
    {
        $shop = (object) ['access_token' => 'tok', 'shop_cipher' => 'cipher', 'id' => 'shop-uuid', 'channel_id' => 'chan'];
        $product = (object) ['id' => 'p1', 'name' => 'Produk', 'category_id' => null];
        $variant = (object) ['id' => 'v1', 'sku' => 'V1-SKU', 'sell_price' => 1000];

        $media = [
            (object) ['variant_id' => null, 'media_type' => 'image', 'url' => 'https://x/main.jpg'],
            (object) ['variant_id' => null, 'media_type' => 'video', 'url' => 'https://x/clip.mp4'],
            (object) ['variant_id' => 'v1', 'media_type' => 'image', 'url' => 'https://x/var.jpg'],
        ];

        $captured = null;
        $client = Mockery::mock(TikTokClient::class);
        $client->shouldReceive('request')
            ->with('POST', '/product/202309/products', Mockery::any(), Mockery::any(), 'tok')
            ->andReturnUsing(function (...$args) use (&$captured) {
                $captured = $args[3];

                return ['data' => ['product_id' => 'TT-1']];
            });

        $shopRepo = Mockery::mock(ChannelShopRepository::class);
        $shopRepo->shouldReceive('findByShopId')->andReturn($shop);

        $productRepo = Mockery::mock(ChannelProductRepository::class);
        $productRepo->shouldReceive('findById')->andReturn($product);
        $productRepo->shouldReceive('getVariantsByProductId')->andReturn(collect([$variant]));
        $productRepo->shouldReceive('getMediaByProductId')->andReturn($media);
        $productRepo->shouldReceive('getVariantOptions')->with('v1')
            ->andReturn([(object) ['attribute_name' => 'Warna', 'value' => 'Merah']]);
        $productRepo->shouldReceive('getProductSpecifications')->andReturn([]);
        $productRepo->shouldReceive('upsertChannelMapping')->andReturn('pcm-1');
        $productRepo->shouldReceive('upsertVariantChannelMapping')->andReturnNull();

        $uploader = Mockery::mock(TikTokImageUploader::class);
        $uploader->shouldReceive('upload')->with(['https://x/main.jpg'], 'tok')
            ->andReturn(['uris' => ['main-uri'], 'errors' => []]);
        $uploader->shouldReceive('upload')->with(['https://x/var.jpg'], 'tok')
            ->andReturn(['uris' => ['var-uri'], 'errors' => []]);
        $uploader->shouldReceive('uploadVideo')->with('https://x/clip.mp4', 'tok')->andReturn('vid-1');

        $service = new TikTokProductService($client, new TikTokProductMapper(), $shopRepo, $productRepo, $uploader);
        $service->pushProduct('p1', 'SHOP');

        $this->assertSame([['uri' => 'main-uri']], $captured['main_images']);
        $this->assertSame(['id' => 'vid-1'], $captured['video']);
        $this->assertSame(['uri' => 'var-uri'], $captured['skus'][0]['sales_attributes'][0]['sku_img']);
    }

    public function test_push_product_throws_when_product_has_no_image(): void
    {
        $shop = (object) ['access_token' => 'tok', 'shop_cipher' => 'cipher', 'id' => 'shop-uuid', 'channel_id' => 'chan'];
        $product = (object) ['id' => 'p1', 'name' => 'Produk', 'category_id' => null];

        $client = Mockery::mock(TikTokClient::class);
        $client->shouldNotReceive('request');

        $shopRepo = Mockery::mock(ChannelShopRepository::class);
        $shopRepo->shouldReceive('findByShopId')->andReturn($shop);

        $productRepo = Mockery::mock(ChannelProductRepository::class);
        $productRepo->shouldReceive('findById')->andReturn($product);
        $productRepo->shouldReceive('getVariantsByProductId')->andReturn(collect([]));
        $productRepo->shouldReceive('getMediaByProductId')->andReturn([]);

        $uploader = Mockery::mock(TikTokImageUploader::class);
        $uploader->shouldReceive('upload')->andReturn(['uris' => [], 'errors' => []]);

        $service = new TikTokProductService(
            $client,
            Mockery::mock(TikTokProductMapper::class),
            $shopRepo,
            $productRepo,
            $uploader
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('belum memiliki gambar');
        $service->pushProduct('p1', 'SHOP');
    }
}
