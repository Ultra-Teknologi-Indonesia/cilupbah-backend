<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Channel\Services\TikTokClient;
use Modules\Outbound\Models\Courier;
use Modules\Outbound\Services\CourierMappingService;

class SyncCourierLogosCommand extends Command
{
    protected $signature = 'courier:sync-logos {--channel=all : shopee|tiktok|lazada|all}';
    protected $description = 'Pull courier logos from connected marketplace channels and store to S3';

    public function __construct(
        private readonly CourierMappingService $mapper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $channel = $this->option('channel');
        $logos = [];

        if (in_array($channel, ['all', 'shopee'])) {
            $logos = array_merge($logos, $this->fromShopee());
        }

        if (in_array($channel, ['all', 'lazada'])) {
            $logos = array_merge($logos, $this->fromLazada());
        }

        if (in_array($channel, ['all', 'tiktok'])) {
            $logos = array_merge($logos, $this->fromTikTok());
        }

        if (empty($logos)) {
            $this->warn('Tidak ada logo yang ditemukan. Pastikan ada toko yang terhubung.');
            return self::SUCCESS;
        }

        $logos = collect($logos)->unique('code')->values()->all();

        $this->info(sprintf('Ditemukan %d logo kurir. Mulai download ke S3...', count($logos)));

        $saved = 0;
        foreach ($logos as $logo) {
            $result = $this->downloadAndStore($logo['code'], $logo['name'], $logo['logo_url']);
            if ($result) {
                $saved++;
            }
        }

        $this->info("Selesai. {$saved} logo tersimpan di S3 dan couriers table diperbarui.");

        return self::SUCCESS;
    }

    private function fromShopee(): array
    {
        $shop = ChannelShop::whereHas('channel', fn ($q) => $q->where('code', 'shopee'))
            ->whereNull('disconnected_at')
            ->first();

        if (! $shop) {
            $this->warn('Tidak ada toko Shopee yang terhubung.');
            return [];
        }

        $this->info("Mengambil logistics dari Shopee ({$shop->shop_name})...");

        try {
            $service = app(ShopeeOrderService::class);
            $list = $service->getLogistics($shop->shop_id);

            $logos = [];
            foreach ($list as $item) {
                $logoUrl = $item['logistics_channel_logo'] ?? null;
                $name = $item['logistics_channel_name'] ?? '';

                if (! $name) {
                    continue;
                }

                $code = $this->mapper->resolveCode($name);
                $this->mapper->record('shopee', $name, $item['logistics_channel_id'] ?? null);

                if ($logoUrl) {
                    $logos[] = [
                        'code' => $code,
                        'name' => $name,
                        'logo_url' => $logoUrl,
                        'source' => 'shopee',
                    ];
                    $this->line("  ✓ {$name} → {$code}");
                } else {
                    $this->line("  ✗ {$name} → {$code} (tidak ada logo)");
                }
            }

            return $logos;
        } catch (\Throwable $e) {
            $this->error("Gagal ambil Shopee logistics: {$e->getMessage()}");
            return [];
        }
    }

    private function fromLazada(): array
    {
        $shop = ChannelShop::whereHas('channel', fn ($q) => $q->where('code', 'lazada'))
            ->whereNull('disconnected_at')
            ->first();

        if (! $shop) {
            $this->warn('Tidak ada toko Lazada yang terhubung.');
            return [];
        }

        $this->info("Mengambil shipment providers dari Lazada ({$shop->shop_name})...");

        try {
            $service = app(LazadaOrderService::class);
            $list = $service->getShipmentProviders($shop->shop_id);

            $logos = [];
            foreach ($list as $item) {
                $name = $item['name'] ?? '';
                $logoUrl = $item['logo_url'] ?? $item['logo'] ?? null;

                if (! $name) {
                    continue;
                }

                $code = $this->mapper->resolveCode($name);
                $this->mapper->record('lazada', $name, $item['id'] ?? null);

                if ($logoUrl) {
                    $logos[] = [
                        'code' => $code,
                        'name' => $name,
                        'logo_url' => $logoUrl,
                        'source' => 'lazada',
                    ];
                    $this->line("  ✓ {$name} → {$code}");
                } else {
                    $this->line("  ✗ {$name} → tidak ada logo");
                }
            }

            return $logos;
        } catch (\Throwable $e) {
            $this->error("Gagal ambil Lazada providers: {$e->getMessage()}");
            return [];
        }
    }

    private function fromTikTok(): array
    {
        $shop = ChannelShop::whereHas('channel', fn ($q) => $q->where('code', 'tiktok'))
            ->whereNull('disconnected_at')
            ->first();

        if (! $shop) {
            $this->warn('Tidak ada toko TikTok yang terhubung.');
            return [];
        }

        $this->info("Mengambil shipping providers dari TikTok ({$shop->shop_name})...");

        try {
            $client = app(TikTokClient::class);
            $cipher = $shop->shop_cipher ?? '';
            $token = $shop->access_token;
            $queries = ['shop_cipher' => $cipher];

            $whRes = $client->request('GET', '/logistics/202309/warehouses', $queries, [], $token);
            $warehouses = $whRes['data']['warehouses'] ?? [];

            $logos = [];
            $seen = [];

            foreach ($warehouses as $warehouse) {
                $warehouseId = $warehouse['id'] ?? null;
                if (! $warehouseId) {
                    continue;
                }

                $doRes = $client->request('GET', "/logistics/202309/warehouses/{$warehouseId}/delivery_options", $queries, [], $token);
                $deliveryOptions = $doRes['data']['delivery_options'] ?? [];

                foreach ($deliveryOptions as $deliveryOption) {
                    $deliveryOptionId = $deliveryOption['id'] ?? null;
                    if (! $deliveryOptionId) {
                        continue;
                    }

                    $spRes = $client->request('GET', "/logistics/202309/delivery_options/{$deliveryOptionId}/shipping_providers", $queries, [], $token);
                    $providers = $spRes['data']['shipping_providers'] ?? [];

                    foreach ($providers as $item) {
                        $name = $item['name'] ?? $item['shipping_provider_name'] ?? '';
                        if (! $name || isset($seen[$name])) {
                            continue;
                        }
                        $seen[$name] = true;

                        $code = $this->mapper->resolveCode($name);
                        $this->mapper->record('tiktok', $name, $item['id'] ?? $item['shipping_provider_id'] ?? null);
                        $logoUrl = $item['logo_url'] ?? $item['logo'] ?? null;

                        if ($logoUrl) {
                            $logos[] = [
                                'code' => $code,
                                'name' => $name,
                                'logo_url' => $logoUrl,
                                'source' => 'tiktok',
                            ];
                            $this->line("  ✓ {$name} → {$code}");
                        } else {
                            $this->line("  ✗ {$name} → tidak ada logo");
                        }
                    }
                }
            }

            return $logos;
        } catch (\Throwable $e) {
            $this->error("Gagal ambil TikTok providers: {$e->getMessage()}");
            return [];
        }
    }

    private function downloadAndStore(string $code, string $name, string $logoUrl): bool
    {
        try {
            $response = Http::timeout(15)->get($logoUrl);

            if (! $response->successful()) {
                $this->warn("  ✗ Download gagal untuk {$name}: HTTP {$response->status()}");
                return false;
            }

            $content = $response->body();
            $contentType = $response->header('Content-Type');

            $ext = match (true) {
                str_contains($contentType, 'svg') => 'svg',
                str_contains($contentType, 'png') => 'png',
                str_contains($contentType, 'webp') => 'webp',
                str_contains($contentType, 'gif') => 'gif',
                default => 'png',
            };

            $path = "couriers/{$code}.{$ext}";

            Storage::disk('s3')->put($path, $content, 'public');

            $s3Url = Storage::disk('s3')->url($path);

            Courier::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'logo_url' => $s3Url,
                    'is_active' => true,
                ]
            );

            $this->info("  ✓ {$name} → s3://{$path}");

            return true;
        } catch (\Throwable $e) {
            $this->warn("  ✗ Gagal simpan {$name}: {$e->getMessage()}");
            return false;
        }
    }
}
