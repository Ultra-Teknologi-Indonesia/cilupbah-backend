<?php

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Product\Services\ChannelSkuHealth;

class MonitorChannelSkuHealth extends Command
{
    protected $signature = 'channel:monitor-sku-health';

    protected $description = 'Alert saat invarian SKU channel bergeser: listing terpecah, SKU induk turunan varian, atau varian ber-SKU placeholder';

    public function __construct(private ChannelSkuHealth $health)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $ukuran = [
            'listing terpecah ke lebih dari satu master' => $this->health->listingTerpecah(),
            'SKU induk yang menyalin SKU varian' => $this->health->masterSkuTurunanVarian()->count(),
            'varian ber-SKU placeholder channel' => $this->health->varianPlaceholder()->count(),
        ];

        $melenceng = array_filter($ukuran);

        foreach ($ukuran as $label => $jml) {
            $this->line(str_pad((string) $jml, 8) . $label);
        }

        if (! $melenceng) {
            $this->info('Sehat: ketiga invarian SKU masih nol.');

            return self::SUCCESS;
        }

        foreach ($melenceng as $label => $jml) {
            $this->pushAlert("{$jml} {$label}", ['ukuran' => $label, 'jumlah' => $jml]);
        }

        $this->warn('Jalankan products:repair-channel-sku untuk melihat rinciannya.');

        return self::SUCCESS;
    }

    private function pushAlert(string $message, array $context): void
    {
        Log::warning('[sku-health] ' . $message, $context);

        if (function_exists('Sentry\\captureMessage')) {
            \Sentry\captureMessage('[sku-health] ' . $message);
        }

        $this->warn($message);
    }
}
