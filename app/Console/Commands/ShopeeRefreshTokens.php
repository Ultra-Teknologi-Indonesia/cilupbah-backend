<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Services\ShopeeAuthService;

class ShopeeRefreshTokens extends Command
{
    protected $signature = 'shopee:refresh-tokens {--hours=2 : Refresh token yang kedaluwarsa dalam N jam ke depan}';

    protected $description = 'Refresh access token toko Shopee yang mendekati kedaluwarsa (token Shopee hanya 4 jam)';

    public function handle(ShopeeAuthService $authService): int
    {
        $summary = $authService->refreshExpiringTokens((int) $this->option('hours'));

        $pesan = sprintf(
            'Shopee token refresh: %d diperbarui, %d gagal, %d dilewati.',
            $summary['refreshed'],
            $summary['failed'],
            $summary['skipped'],
        );

        if ($summary['failed'] > 0) {
            $this->error($pesan);

            return self::FAILURE;
        }

        $this->info($pesan);

        return self::SUCCESS;
    }
}
