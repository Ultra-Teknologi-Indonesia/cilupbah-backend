<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Services\LazadaAuthService;

/**
 * Refresh otomatis access token toko Lazada yang akan kedaluwarsa.
 * Token Lazada hanya berlaku ±7 hari — dijadwalkan harian (lihat routes/console.php).
 * Tidak pernah melempar: kegagalan per-toko dicatat di log oleh service.
 */
class LazadaRefreshTokens extends Command
{
    protected $signature = 'lazada:refresh-tokens {--hours=48 : Refresh token yang kedaluwarsa dalam N jam ke depan}';

    protected $description = 'Refresh access token toko Lazada yang mendekati kedaluwarsa';

    public function handle(LazadaAuthService $authService): int
    {
        $summary = $authService->refreshExpiringTokens((int) $this->option('hours'));

        $this->info(sprintf(
            'Lazada token refresh: %d diperbarui, %d gagal, %d dilewati.',
            $summary['refreshed'],
            $summary['failed'],
            $summary['skipped'],
        ));

        return self::SUCCESS;
    }
}
