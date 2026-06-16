<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Services\ReviewStatusPoller;

class PollProductReviewStatus extends Command
{
    protected $signature = 'products:poll-review-status';

    protected $description = 'Tarik status review produk dari marketplace (fallback bila webhook terlewat) dan simpan ke DB';

    public function handle(ReviewStatusPoller $poller): int
    {
        $summary = $poller->pollAll();

        $this->info(sprintf(
            'Polling status review: %d toko, %d listing dicek, %d diperbarui.',
            $summary['shops'],
            $summary['checked'],
            $summary['updated'],
        ));

        return self::SUCCESS;
    }
}
