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

class SyncCourierLogosCommand extends Command
{
    protected $signature = 'courier:sync-logos {--channel=all : shopee|tiktok|lazada|all}';
    protected $description = 'Pull courier logos from connected marketplace channels and store to S3';

    private array $codeAliases = [
        'j&t express' => 'jnt',
        'j&t' => 'jnt',
        'jnt express' => 'jnt',
        'jne' => 'jne',
        'jne express' => 'jne',
        'jne reguler' => 'jne',
        'sicepat' => 'sicepat',
        'si cepat' => 'sicepat',
        'sicepat express' => 'sicepat',
        'anteraja' => 'anteraja',
        'ninja express' => 'ninja',
        'ninja van' => 'ninja',
        'ninja xpress' => 'ninja',
        'shopee express' => 'spx',
        'spx' => 'spx',
        'spx express' => 'spx',
        'spx standard' => 'spx',
        'spx instant' => 'spx_instant',
        'shopee xpress' => 'spx',
        'id express' => 'idexpress',
        'idx' => 'idexpress',
        'lion parcel' => 'lionparcel',
        'gosend' => 'gosend',
        'grabexpress' => 'grabexpress',
        'grab express' => 'grabexpress',
        'grab' => 'grabexpress',
        'pos indonesia' => 'pos',
        'tiki' => 'tiki',
        'sap express' => 'sap',
        'sap' => 'sap',
        'wahana' => 'wahana',
        'rex' => 'rex',
        'tiktok shipping' => 'tiktok_shipping',
        'lazada logistics' => 'lazada_logistics',
        'lex id' => 'lex',
    ];

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

                if (! $logoUrl || ! $name) {
                    continue;
                }

                $code = $this->resolveCode($name);
                $logos[] = [
                    'code' => $code,
                    'name' => $name,
                    'logo_url' => $logoUrl,
                    'source' => 'shopee',
                ];

                $this->line("  ✓ {$name} → {$code}");
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

                $code = $this->resolveCode($name);

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

        $this->info("Mengambil delivery options dari TikTok ({$shop->shop_name})...");

        try {
            $client = app(TikTokClient::class);
            $queries = ['shop_cipher' => $shop->shop_cipher ?? ''];
            $res = $client->request('GET', '/fulfillment/202309/shipping_providers', $queries, [], $shop->access_token);

            $providers = $res['data']['shipping_providers'] ?? [];
            $logos = [];

            foreach ($providers as $item) {
                $name = $item['shipping_provider_name'] ?? '';
                $logoUrl = $item['logo_url'] ?? $item['logo'] ?? null;

                if (! $name) {
                    continue;
                }

                $code = $this->resolveCode($name);

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

            return $logos;
        } catch (\Throwable $e) {
            $this->error("Gagal ambil TikTok providers: {$e->getMessage()}");
            return [];
        }
    }

    private function resolveCode(string $name): string
    {
        $lower = strtolower(trim($name));

        if (isset($this->codeAliases[$lower])) {
            return $this->codeAliases[$lower];
        }

        foreach ($this->codeAliases as $keyword => $code) {
            if (str_contains($lower, $keyword)) {
                return $code;
            }
        }

        return str_replace([' ', '.', '-'], '_', $lower);
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
