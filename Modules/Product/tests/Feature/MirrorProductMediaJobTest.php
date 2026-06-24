<?php

namespace Modules\Product\Tests\Feature;

use App\Services\UploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Modules\Product\Jobs\MirrorProductMediaJob;
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
            'name' => 'Kategori ' . Str::random(5),
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

        $media = Mockery::mock();
        $media->uuid = 'mirror-uuid-1';
        $media->shouldReceive('getUrl')->andReturn('https://assets.ultra-fit.id/9/mirrored.jpeg');

        $uploads = Mockery::mock(UploadService::class);
        $uploads->shouldReceive('storeFromUrl')
            ->once()
            ->with($external)
            ->andReturn($media);

        (new MirrorProductMediaJob($mediaId))->handle($uploads);

        $row = DB::table('product_media')->where('id', $mediaId)->first();
        $this->assertSame('https://assets.ultra-fit.id/9/mirrored.jpeg', $row->url);
        $this->assertSame('mirror-uuid-1', $row->media_uuid);
    }

    public function test_internal_media_is_left_untouched(): void
    {
        $mediaId = $this->seedMedia('https://assets.ultra-fit.id/5/already-internal.jpeg');

        $uploads = Mockery::mock(UploadService::class);
        $uploads->shouldNotReceive('storeFromUrl');

        (new MirrorProductMediaJob($mediaId))->handle($uploads);

        $row = DB::table('product_media')->where('id', $mediaId)->first();
        $this->assertSame('https://assets.ultra-fit.id/5/already-internal.jpeg', $row->url);
    }

    public function test_failed_mirror_throws_to_trigger_retry(): void
    {
        $mediaId = $this->seedMedia('https://p16-oec-ttp.tiktokcdn-us.com/tos-alisg-i-aphluv4xwc-sg/cannotreach');

        $uploads = Mockery::mock(UploadService::class);
        $uploads->shouldReceive('storeFromUrl')->once()->andReturnNull();

        $this->expectException(\RuntimeException::class);

        (new MirrorProductMediaJob($mediaId))->handle($uploads);
    }
}
