<?php

declare(strict_types=1);

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Services\ChannelSyncSettingService;

final class AutoPauseChannelSyncCommand extends Command
{
    protected $signature = 'channel:auto-pause-sync';

    protected $description = 'Jeda sinkronisasi channel otomatis setelah jam operasional yang ditentukan.';

    public function handle(ChannelSyncSettingService $settings): int
    {
        if ($settings->autoPauseIfDue()) {
            $this->info('Sinkronisasi channel dijeda otomatis sesuai jadwal.');
        }

        return self::SUCCESS;
    }
}
