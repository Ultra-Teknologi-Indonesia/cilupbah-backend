<?php

namespace Modules\Product\Tests\Feature;

use App\Services\UploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Modules\Product\Jobs\MirrorProductMediaJob;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class MirrorProductMediaJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.disks.s3.url' => 'https://assets.ultra-fit.id']);
    }

    private function seedMedia(string $url): int
    {
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Kategori '.Str::random(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = Str::uuid()->toString();
        DB::table('products')->insert([
            'id' => $productId,
            'category_id' => $categoryId,
            'name' => 'Produk Mirror',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('product_media')->insertGetId([
            'product_id' => $productId,
            'variant_id' => null,
            'media_type' => 'image',
            'url' => $url,
            'sort_order' => 0,
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_external_media_is_remirrored_to_internal_cdn(): void
    {
        $external = 'https://down-id.img.susercontent.com/file/id-11134207-81z1k-mpoc5zd3f8jl2c';
        $mediaId = $this->seedMedia($external);
        $mediaUuid = (string) Str::uuid();
        $uploadId = (string) Str::uuid();

        DB::table('uploads')->insert([
            'id' => $uploadId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('media')->insert([
            'uuid' => $mediaUuid,
            'model_type' => 'App\\Models\\Upload',
            'model_id' => $uploadId,
            'collection_name' => 'file',
            'name' => 'mirrored',
            'file_name' => 'mirrored.jpeg',
            'mime_type' => 'image/jpeg',
            'disk' => 's3',
            'conversions_disk' => 's3',
            'size' => 1,
            'manipulations' => '[]',
            'custom_properties' => '[]',
            'generated_conversions' => '[]',
            'responsive_images' => '[]',
            'order_column' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $media = Mockery::mock(Media::class);
        $media->shouldReceive('setAttribute')
            ->once()
            ->with('uuid', $mediaUuid)
            ->andReturnSelf();
        $media->shouldReceive('getAttribute')
            ->once()
            ->with('uuid')
            ->andReturn($mediaUuid);
        $media->uuid = $mediaUuid;
        $media->shouldReceive('getUrl')->andReturn('https://assets.ultra-fit.id/9/mirrored.jpeg');

        $uploads = Mockery::mock(UploadService::class);
        $uploads->shouldReceive('storeFromUrlStrict')
            ->once()
            ->with($external)
            ->andReturn($media);

        (new MirrorProductMediaJob($mediaId))->handle($uploads);

        $row = DB::table('product_media')->where('id', $mediaId)->first();
        $this->assertSame('https://assets.ultra-fit.id/9/mirrored.jpeg', $row->url);
        $this->assertSame($mediaUuid, $row->media_uuid);
    }

    public function test_internal_media_is_left_untouched(): void
    {
        $mediaId = $this->seedMedia('https://assets.ultra-fit.id/5/already-internal.jpeg');

        $uploads = Mockery::mock(UploadService::class);
        $uploads->shouldNotReceive('storeFromUrlStrict');

        (new MirrorProductMediaJob($mediaId))->handle($uploads);

        $row = DB::table('product_media')->where('id', $mediaId)->first();
        $this->assertSame('https://assets.ultra-fit.id/5/already-internal.jpeg', $row->url);
    }

    public function test_failed_mirror_throws_to_trigger_retry(): void
    {
        $mediaId = $this->seedMedia('https://p16-oec-ttp.tiktokcdn-us.com/tos-alisg-i-aphluv4xwc-sg/cannotreach');

        $uploads = Mockery::mock(UploadService::class);
        $uploads->shouldReceive('storeFromUrlStrict')
            ->once()
            ->andThrow(new \RuntimeException('HTTP 502 upstream image service'));

        $this->expectException(\RuntimeException::class);

        try {
            (new MirrorProductMediaJob($mediaId))->handle($uploads);
        } catch (\RuntimeException $exception) {
            $this->assertSame('HTTP 502 upstream image service', $exception->getMessage());
            throw $exception;
        }
    }
}
