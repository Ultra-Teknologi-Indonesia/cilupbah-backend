<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\ChannelShop;

class ConnectLazadaShopManual extends Command
{
    protected $signature = 'lazada:connect-manual
        {shop_id : Seller ID Lazada}
        {access_token : Access token dari App Console}
        {--refresh-token= : Refresh token (opsional)}
        {--shop-name=Lazada Test Shop : Nama toko}
        {--expires-hours=168 : Masa berlaku token (jam, default 7 hari)}';

    protected $description = 'Hubungkan toko Lazada manual via access_token (uji tanpa OAuth browser)';

    public function handle(): int
    {
        $channelId = DB::table('channels')->where('code', 'lazada')->value('id');
        if (! $channelId) {
            $this->error('Channel lazada belum ter-seed. Jalankan seeder channel dulu.');

            return self::FAILURE;
        }

        $shopId = (string) $this->argument('shop_id');

        $shop = ChannelShop::updateOrCreate(
            ['channel_id' => $channelId, 'shop_id' => $shopId],
            [
                'shop_name' => (string) $this->option('shop-name'),
                'access_token' => (string) $this->argument('access_token'),
                'refresh_token' => $this->option('refresh-token') ?: null,
                'token_expires_at' => now()->addHours((int) $this->option('expires-hours')),
                'is_active' => true,
                'disconnected_at' => null,
                'integration_status' => 'connected',
            ],
        );

        $this->info("Toko Lazada '{$shopId}' terhubung (manual). id={$shop->id}");
        $this->line('Lanjut uji:');
        $this->line("  php artisan lazada:sync-categories {$shopId}");
        $this->line("  php artisan lazada:sync-category-attributes {$shopId}");

        return self::SUCCESS;
    }
}
