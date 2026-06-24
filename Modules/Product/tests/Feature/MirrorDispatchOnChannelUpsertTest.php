<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Product\Jobs\MirrorProductMediaJob;
use Modules\Product\Services\ProductService;
use Tests\TestCase;

class MirrorDispatchOnChannelUpsertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.disks.s3.url' => 'https://assets.ultra-fit.id']);
    }

    private function category(): int
    {
        return (int) DB::table('categories')->insertGetId([
            'name' => 'Belum Dikategorikan',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function channelData(string $sku, string $mediaUrl): array
    {
        return [
            'name' => 'Produk Mirror Hook',
            'category_id' => $this->category(),
            'status' => 'master',
            'sku' => $sku,
            'variants' => [[
                'sku' => $sku,
                'sell_price' => 1000,
                'is_active' => true,
                'media' => [[
                    'media_type' => 'image',
                    'url' => $mediaUrl,
                    'is_primary' => true,
                    'sort_order' => 0,
                ]],
            ]],
        ];
    }

    public function test_external_media_triggers_mirror_job(): void
    {
        Queue::fake();

        app(ProductService::class)->upsertFromChannel(
            $this->channelData('HOOK-EXT', 'https://p16-oec-ttp.tiktokcdn-us.com/tos-alisg-i-aphluv4xwc-sg/abc')
        );

        Queue::assertPushed(MirrorProductMediaJob::class);
    }

    public function test_internal_media_does_not_trigger_mirror_job(): void
    {
        Queue::fake();

        app(ProductService::class)->upsertFromChannel(
            $this->channelData('HOOK-INT', 'https://assets.ultra-fit.id/9/already.jpeg')
        );

        Queue::assertNotPushed(MirrorProductMediaJob::class);
    }
}
