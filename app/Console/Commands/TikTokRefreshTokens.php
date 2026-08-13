<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Services\TikTokAuthService;

class TikTokRefreshTokens extends Command
{
    protected $signature = 'tiktok:refresh-tokens {--hours=2 : Refresh token yang kedaluwarsa dalam N jam ke depan}';

    protected $description = 'Refresh access token toko TikTok yang mendekati kedaluwarsa (token TikTok hanya 2 jam)';

    public function handle(TikTokAuthService $authService): int
    {
        $summary = $authService->refreshExpiringTokens((int) $this->option('hours'));

        $pesan = sprintf(
            'TikTok token refresh: %d diperbarui, %d gagal, %d dilewati.',
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
