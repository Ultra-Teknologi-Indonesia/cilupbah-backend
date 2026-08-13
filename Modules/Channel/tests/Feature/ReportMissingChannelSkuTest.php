<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\ProductSyncLog;
use Modules\Product\Services\ChannelSkuHealth;
use Tests\TestCase;

class ReportMissingChannelSkuTest extends TestCase
{
    use RefreshDatabase;

    private ChannelShop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $channel = Channel::firstOrCreate(['code' => 'shopee'], ['name' => 'Shopee', 'is_active' => true]);

        $this->shop = ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => 'SHP-LAPOR',
            'shop_name' => 'Toko Lapor',
            'is_active' => true,
        ]);
    }

    private function catatDilewati(string $listing, ?string $externalSkuId): void
    {
        ProductSyncLog::record([
            'channel_shop_id' => $this->shop->id,
            'action' => ProductSyncLog::ACTION_DOWNLOAD,
            'status' => ProductSyncLog::STATUS_FAILED,
            'payload' => [
                'external_product_id' => $listing,
                'external_sku_id' => $externalSkuId,
            ],
            'error_message' => 'Model tidak punya SKU di channel',
        ]);
    }

    public function test_model_yang_sama_tidak_dihitung_dua_kali_saat_listing_ditarik_ulang(): void
    {
        foreach (['M-1', 'M-2', 'M-3'] as $model) {
            $this->catatDilewati('8729043644', $model);
            $this->catatDilewati('8729043644', $model);
        }

        $rows = app(ChannelSkuHealth::class)->modelTanpaSku(24);

        $this->assertCount(1, $rows);
        $this->assertSame(3, (int) $rows[0]->jml, 'Enam baris log dari tiga model tetap terhitung tiga');
        $this->assertSame('8729043644', $rows[0]->listing);
    }

    public function test_baris_tanpa_external_sku_id_tetap_dihitung_sendiri_sendiri(): void
    {
        $this->catatDilewati('55713362773', null);
        $this->catatDilewati('55713362773', null);

        $rows = app(ChannelSkuHealth::class)->modelTanpaSku(24);

        $this->assertSame(2, (int) $rows[0]->jml, 'Tanpa id model tidak ada yang bisa dibedakan, jadi tidak digabung');
    }

    public function test_listing_dikelompokkan_per_toko_dan_diurut_dari_terbanyak(): void
    {
        $this->catatDilewati('kecil', 'A-1');

        foreach (['B-1', 'B-2', 'B-3'] as $model) {
            $this->catatDilewati('besar', $model);
        }

        $rows = app(ChannelSkuHealth::class)->modelTanpaSku(24);

        $this->assertSame(['besar', 'kecil'], $rows->pluck('listing')->all());
        $this->assertSame('shopee', $rows[0]->channel);
        $this->assertSame('Toko Lapor', $rows[0]->shop_name);
    }

    public function test_log_di_luar_rentang_jam_diabaikan(): void
    {
        $this->catatDilewati('lama', 'L-1');
        DB::table('product_sync_logs')->update(['created_at' => now()->subDays(3)]);

        $this->catatDilewati('baru', 'B-1');

        $rows = app(ChannelSkuHealth::class)->modelTanpaSku(24);

        $this->assertSame(['baru'], $rows->pluck('listing')->all());
    }

    public function test_perintah_laporan_berjalan_dan_menampilkan_ringkasan(): void
    {
        $this->catatDilewati('27924754636', 'X-1');

        $this->artisan('channel:report-missing-sku')
            ->expectsOutputToContain('1 model tanpa SKU di 1 listing')
            ->assertSuccessful();
    }
}
