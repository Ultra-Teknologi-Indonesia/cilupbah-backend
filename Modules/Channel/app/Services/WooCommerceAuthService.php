<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Repositories\ChannelRepository;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Support\OAuthFlow;

class WooCommerceAuthService
{
    private const CHANNEL = 'woocommerce';
    private const CONNECT_PREFIX = 'woocommerce:connect:';
    private const WEBHOOK_TOPICS = ['order.created', 'order.updated', 'product.updated'];

    public function __construct(
        protected WooCommerceClient $client,
        protected ChannelShopRepository $shopRepository,
        protected ChannelRepository $channelRepository,
    ) {}

    public function buildAuthorizeUrl(string $storeUrl): string
    {
        $storeUrl = $this->normalizeUrl($storeUrl);
        $state = OAuthFlow::issueState(self::CHANNEL);
        Cache::put(self::CONNECT_PREFIX . $state, $storeUrl, now()->addMinutes(10));

        $query = http_build_query([
            'app_name' => (string) config('services.woocommerce.app_name', 'Cilupbah'),
            'scope' => 'read_write',
            'user_id' => $state,
            'return_url' => OAuthFlow::frontendUrl(self::CHANNEL, ['count' => 1]) ?? $storeUrl,
            'callback_url' => $this->callbackUrl(),
        ]);

        return $storeUrl . '/wc-auth/v1/authorize?' . $query;
    }

    public function handleCallback(array $payload): array
    {
        $state = (string) ($payload['user_id'] ?? '');
        $storeUrl = Cache::pull(self::CONNECT_PREFIX . $state);

        if (! $storeUrl || ! OAuthFlow::consumeState($state, self::CHANNEL)) {
            throw new \RuntimeException('Sesi otorisasi WooCommerce tidak valid atau kedaluwarsa.', 422);
        }

        $consumerKey = (string) ($payload['consumer_key'] ?? '');
        $consumerSecret = (string) ($payload['consumer_secret'] ?? '');

        if ($consumerKey === '' || $consumerSecret === '') {
            throw new \RuntimeException('WooCommerce tidak mengirimkan kredensial.', 422);
        }

        return $this->persistShop($storeUrl, $consumerKey, $consumerSecret);
    }

    public function handleConnect(array $data): array
    {
        $storeUrl = $this->normalizeUrl((string) ($data['store_url'] ?? ''));
        $consumerKey = (string) ($data['consumer_key'] ?? '');
        $consumerSecret = (string) ($data['consumer_secret'] ?? '');

        if ($storeUrl === '' || $consumerKey === '' || $consumerSecret === '') {
            throw new \RuntimeException('URL toko, consumer key, dan consumer secret wajib diisi.', 422);
        }

        $this->verifyCredentials($storeUrl, $consumerKey, $consumerSecret);

        return $this->persistShop($storeUrl, $consumerKey, $consumerSecret, $data['shop_name'] ?? null);
    }

    protected function persistShop(string $storeUrl, string $consumerKey, string $consumerSecret, ?string $shopName = null): array
    {
        $channelId = $this->channelRepository->getIdByCode(self::CHANNEL);
        $shopId = $this->deriveShopId($storeUrl);
        $shopName = $shopName ?: ('WooCommerce ' . parse_url($storeUrl, PHP_URL_HOST));
        $webhookSecret = Str::random(40);

        $shop = $this->shopRepository->updateOrCreateShop($shopId, [
            'channel_id' => $channelId,
            'shop_name' => $shopName,
            'store_url' => $storeUrl,
            'consumer_key' => $consumerKey,
            'consumer_secret' => $consumerSecret,
            'webhook_secret' => $webhookSecret,
            'is_active' => true,
            'integration_status' => 'normal',
            'last_error' => null,
        ]);

        $this->registerWebhooks($shop);

        return ['shop_id' => $shopId, 'shop_name' => $shopName];
    }

    public function getStores(): array
    {
        return $this->shopRepository->getShopsByChannelCode(self::CHANNEL)
            ->map(fn (ChannelShop $shop) => [
                'id' => $shop->id,
                'shop_id' => $shop->shop_id,
                'shop_name' => $shop->shop_name,
                'store_url' => $shop->store_url,
                'is_active' => $shop->is_active,
                'token_status' => $shop->is_active && $shop->consumer_key ? 'active' : 'disconnected',
                'connected_at' => $shop->created_at,
                'updated_at' => $shop->updated_at,
            ])->toArray();
    }

    public function getStoreModel(string $id): ChannelShop
    {
        return $this->requireShop($id);
    }

    public function getStoreDetail(string $id): array
    {
        $shop = $this->requireShop($id);

        return [
            'id' => $shop->id,
            'shop_id' => $shop->shop_id,
            'shop_name' => $shop->shop_name,
            'store_url' => $shop->store_url,
            'is_active' => $shop->is_active,
            'token_status' => $shop->is_active && $shop->consumer_key ? 'active' : 'disconnected',
            'connected_at' => $shop->created_at,
            'updated_at' => $shop->updated_at,
        ];
    }

    public function disconnectStore(string $id): void
    {
        $shop = $this->requireShop($id);

        $this->deleteWebhooks($shop);

        $this->shopRepository->updateShop($shop, [
            'is_active' => false,
            'consumer_key' => null,
            'consumer_secret' => null,
            'webhook_secret' => null,
            'external_webhook_ids' => null,
        ]);
    }

    protected function registerWebhooks(ChannelShop $shop): void
    {
        try {
            $ids = [];
            foreach (self::WEBHOOK_TOPICS as $topic) {
                $res = $this->client->post($shop, 'webhooks', [
                    'name' => 'Cilupbah ' . $topic,
                    'topic' => $topic,
                    'delivery_url' => $this->webhookUrl(),
                    'secret' => $shop->webhook_secret,
                    'status' => 'active',
                ]);

                if (! empty($res['id'])) {
                    $ids[] = $res['id'];
                }
            }

            if (! empty($ids)) {
                $this->shopRepository->updateShop($shop, ['external_webhook_ids' => $ids]);
            }
        } catch (\Throwable $e) {
            Log::warning('WooCommerce: gagal daftar webhook otomatis: ' . $e->getMessage(), [
                'shop_id' => $shop->shop_id,
            ]);
        }
    }

    protected function deleteWebhooks(ChannelShop $shop): void
    {
        foreach ((array) $shop->external_webhook_ids as $webhookId) {
            try {
                $this->client->delete($shop, "webhooks/{$webhookId}", ['force' => true]);
            } catch (\Throwable $e) {
                Log::warning('WooCommerce: gagal hapus webhook: ' . $e->getMessage(), [
                    'shop_id' => $shop->shop_id,
                    'webhook_id' => $webhookId,
                ]);
            }
        }
    }

    protected function verifyCredentials(string $storeUrl, string $consumerKey, string $consumerSecret): void
    {
        $probe = new ChannelShop([
            'store_url' => $storeUrl,
            'consumer_key' => $consumerKey,
            'consumer_secret' => $consumerSecret,
        ]);

        try {
            $this->client->get($probe, 'system_status');
        } catch (\Throwable $e) {
            throw new \RuntimeException('Gagal terhubung ke WooCommerce. Periksa URL dan kredensial: ' . $e->getMessage(), 422);
        }
    }

    protected function deriveShopId(string $storeUrl): string
    {
        $host = parse_url($storeUrl, PHP_URL_HOST) ?: $storeUrl;
        $path = trim((string) parse_url($storeUrl, PHP_URL_PATH), '/');

        return 'woo_' . strtolower($host . ($path !== '' ? '/' . $path : ''));
    }

    protected function normalizeUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        return rtrim($url, '/');
    }

    protected function callbackUrl(): string
    {
        return (string) (config('services.woocommerce.callback_url')
            ?: rtrim((string) config('app.url'), '/') . '/api/v1/woocommerce/callback');
    }

    protected function webhookUrl(): string
    {
        return rtrim((string) config('app.url'), '/') . '/api/v1/woocommerce/webhook';
    }

    protected function requireShop(string $id): ChannelShop
    {
        $shop = $this->shopRepository->findByUuid($id);
        $channelId = $this->channelRepository->getIdByCode(self::CHANNEL);

        if (! $shop || $shop->channel_id !== $channelId) {
            throw new \RuntimeException('Toko tidak ditemukan', 404);
        }

        return $shop;
    }
}
