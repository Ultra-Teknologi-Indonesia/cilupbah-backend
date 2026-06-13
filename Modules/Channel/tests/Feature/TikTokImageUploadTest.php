<?php

namespace Modules\Channel\Tests\Feature;

use App\Services\UploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Modules\Channel\Services\ChannelMediaResolver;
use Modules\Channel\Services\TikTokClient;
use Modules\Channel\Services\TikTokImageUploader;
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
        $resolver->shouldReceive('bytes')->with('u1')->andReturn('BYTES');

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
}
