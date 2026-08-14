<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Services\LazadaAuthService;

class LazadaRefreshTokens extends Command
{
    protected $signature = 'lazada:refresh-tokens {--hours=48 : Refresh token yang kedaluwarsa dalam N jam ke depan}';

    protected $description = 'Refresh access token toko Lazada yang mendekati kedaluwarsa';

    public function handle(LazadaAuthService $authService): int
    {
        $summary = $authService->refreshExpiringTokens((int) $this->option('hours'));

        $pesan = sprintf(
            'Lazada token refresh: %d diperbarui, %d gagal, %d dilewati.',
            $summary['refreshed'],
            $summary['failed'],
            $summary['skipped'],
        );

        if ($summary['failed'] > 0) {
            $this->warn($pesan);

            return self::SUCCESS;
        }

        $this->info($pesan);

        return self::SUCCESS;
    }
}
