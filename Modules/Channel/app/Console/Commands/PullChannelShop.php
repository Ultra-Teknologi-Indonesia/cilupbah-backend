<?php

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Services\ChannelDownloadService;

class PullChannelShop extends Command
{
    protected $signature = 'channel:pull-shop {channel} {shop}';

    protected $description = 'Tarik produk satu toko. Dipanggil per proses oleh channel:audit-sku-coverage --apply agar memori dilepas antar toko';

    protected $hidden = true;

    public function handle(ChannelDownloadService $downloader): int
    {
        try {
            $downloader->pull((string) $this->argument('channel'), (string) $this->argument('shop'));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
