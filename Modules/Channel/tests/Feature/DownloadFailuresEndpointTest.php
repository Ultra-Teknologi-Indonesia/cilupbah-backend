<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Models\DownloadTransaction;
use Modules\Product\Models\ProductSyncLog;
use Tests\TestCase;

class DownloadFailuresEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_failures_endpoint_groups_reasons_and_returns_samples(): void
    {
        $user = $this->createPrivilegedUser();

        $channel = Channel::create(['code' => 'shopee', 'name' => 'Shopee', 'is_active' => true]);
        $shop = ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => 'SHP-1',
            'shop_name' => 'Toko',
            'access_token' => 'a',
            'refresh_token' => 'r',
            'token_expires_at' => now()->addDay(),
            'is_active' => true,
        ]);

        $trx = DownloadTransaction::create([
            'channel_shop_id' => $shop->id,
            'executed_by' => $user->id,
            'state' => DownloadTransaction::STATE_DONE,
            'all_product' => 3,
            'total_downloaded' => 1,
            'total_failed' => 2,
            'progress_percent' => 100,
        ]);

        foreach ([['29669780949', 'Case A'], ['46603987416', 'Case B']] as [$extId, $title]) {
            ProductSyncLog::record([
                'channel_shop_id' => $shop->id,
                'action' => ProductSyncLog::ACTION_DOWNLOAD,
                'status' => ProductSyncLog::STATUS_FAILED,
                'payload' => ['external_product_id' => $extId, 'title' => $title],
                'error_message' => 'SQLSTATE[23505]: Unique violation: duplicate key value violates unique constraint "products_sku_unique" DETAIL: Key (sku)=() already exists.',
            ]);
        }

        $trx->forceFill([
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->addMinutes(5),
        ])->save();

        $res = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/download-transactions/{$trx->id}/failures")
            ->assertOk();

        $res->assertJsonPath('data.total_failed', 2);
        $res->assertJsonPath('data.logged_failures', 2);
        $res->assertJsonPath('data.reasons.0.reason', 'SKU duplikat atau kosong (produk tanpa SKU bertabrakan)');
        $res->assertJsonPath('data.reasons.0.count', 2);
        $this->assertNotEmpty($res->json('data.samples'));
    }
}
