<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Log;
use Modules\Channel\Exceptions\ChannelTokenException;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Repositories\ChannelRepository;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Repositories\ChannelWarehouseRepository;
use Modules\Channel\Support\ChannelReauthCopy;
use Modules\Channel\Support\LocksTokenRefresh;

class ShopeeAuthService
{
    use LocksTokenRefresh;

    private const REFRESH_TOKEN_TTL_SECONDS = 2592000;

    public function __construct(
        protected ShopeeClient $client,
        protected ChannelShopRepository $shopRepository,
        protected ChannelRepository $channelRepository,
        protected ChannelWarehouseRepository $warehouseRepository,
    ) {}

    public function handleCallback(string $code, string $shopId): array
    {
        $token = $this->client->getAccessToken($code, $shopId);

        $accessToken = $token['access_token'] ?? null;

        if (! $accessToken) {
            $message = $token['message'] ?? ($token['error'] ?? 'Unknown error');
            throw new \Exception('Gagal mengambil access token Shopee: ' . $message);
        }

        $channelId = $this->channelRepository->getIdByCode('shopee');

        $expireIn = (int) ($token['expire_in'] ?? $token['expires_in'] ?? 0);
        $tokenExpiresAt = $expireIn > 0 ? now()->addSeconds($expireIn) : null;
        $refreshExpiresAt = now()->addSeconds(self::REFRESH_TOKEN_TTL_SECONDS);

        $shopName = $this->fetchShopName($shopId, $accessToken) ?? ('Shopee ' . $shopId);

        $this->shopRepository->updateOrCreateShop($shopId, [
            'channel_id' => $channelId,
            'shop_name' => $shopName,
            'access_token' => $accessToken,
            'refresh_token' => $token['refresh_token'] ?? null,
            'token_expires_at' => $tokenExpiresAt,
            'refresh_token_expires_at' => $refreshExpiresAt,
            'is_active' => true,
        ]);

        $this->fetchAndSaveWarehouse($shopId, $accessToken, $channelId);

        return ['shop_id' => $shopId, 'shop_name' => $shopName];
    }

    public function getStores(): array
    {
        return $this->shopRepository->getShopsByChannelCode('shopee')
            ->map(fn (ChannelShop $shop) => [
                'id' => $shop->id,
                'shop_id' => $shop->shop_id,
                'shop_name' => $shop->shop_name,
                'is_active' => $shop->is_active,
                'token_status' => $this->getTokenStatus($shop),
                'connected_at' => $shop->created_at,
                'updated_at' => $shop->updated_at,
            ])->toArray();
    }

    public function getStoreModel(string $id): ChannelShop
    {
        return $this->requireShopeeShop($id);
    }

    public function getStoreDetail(string $id): array
    {
        $shop = $this->requireShopeeShop($id);

        return [
            'id' => $shop->id,
            'shop_id' => $shop->shop_id,
            'shop_name' => $shop->shop_name,
            'is_active' => $shop->is_active,
            'token_status' => $this->getTokenStatus($shop),
            'token_expires_at' => $shop->token_expires_at?->toIso8601String(),
            'refresh_token_expires_at' => $shop->refresh_token_expires_at?->toIso8601String(),
            'connected_at' => $shop->created_at,
            'updated_at' => $shop->updated_at,
        ];
    }

    public function disconnectStore(string $id): void
    {
        $shop = $this->requireShopeeShop($id);

        $this->shopRepository->updateShop($shop, [
            'is_active' => false,
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
            'refresh_token_expires_at' => null,
        ]);
    }

    public function refreshStoreToken(string $id): array
    {
        $shop = $this->requireShopeeShop($id);

        return $this->lockedTokenRefresh($id, 'shopee', $shop->access_token, fn () => $this->performTokenRefresh($id));
    }

    private function performTokenRefresh(string $id): array
    {
        $shop = $this->requireShopeeShop($id);

        if (! $shop->refresh_token) {
            throw new ChannelTokenException(
                ChannelReauthCopy::missingRefreshToken('shopee'),
                permanent: true,
                channelCode: 'shopee',
                rawMessage: 'Refresh token tidak tersedia',
            );
        }

        $response = retry(3, function () use ($shop) {
            return $this->client->refreshAccessToken($shop->refresh_token, $shop->shop_id);
        }, 1000);

        $accessToken = $response['access_token'] ?? null;
        if (! $accessToken) {
            $raw = (string) ($response['message'] ?? ($response['error'] ?? 'Unknown error'));
            $permanent = ChannelReauthCopy::isPermanentFailure($raw);

            Log::warning('Shopee refresh token gagal', ['shop_id' => $shop->shop_id, 'raw' => $raw, 'permanent' => $permanent]);

            throw new ChannelTokenException(
                ChannelReauthCopy::refreshFailure('shopee', $permanent),
                permanent: $permanent,
                channelCode: 'shopee',
                rawMessage: $raw,
            );
        }

        $expireIn = (int) ($response['expire_in'] ?? $response['expires_in'] ?? 0);
        $tokenExpiresAt = $expireIn > 0 ? now()->addSeconds($expireIn) : $shop->token_expires_at;

        $this->shopRepository->updateShop($shop, [
            'access_token' => $accessToken,
            'refresh_token' => $response['refresh_token'] ?? $shop->refresh_token,
            'token_expires_at' => $tokenExpiresAt,
            'refresh_token_expires_at' => now()->addSeconds(self::REFRESH_TOKEN_TTL_SECONDS),
        ]);

        $this->shopRepository->markIntegrationHealthy($id);

        return [
            'shop_id' => $shop->shop_id,
            'shop_name' => $shop->shop_name,
            'token_expires_at' => $tokenExpiresAt?->toIso8601String(),
        ];
    }

    public function refreshExpiringTokens(int $hours = 2): array
    {
        $summary = ['refreshed' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($this->shopRepository->getShopsByChannelCode('shopee') as $shop) {

            $refreshTokenValid = $shop->refresh_token
                && (! $shop->refresh_token_expires_at || $shop->refresh_token_expires_at->isFuture());

            $needsRefresh = $shop->is_active
                && $refreshTokenValid
                && (! $shop->token_expires_at || $shop->token_expires_at->lte(now()->addHours($hours)));

            if (! $needsRefresh) {
                $summary['skipped']++;
                continue;
            }

            try {
                $this->refreshStoreToken($shop->id);
                $summary['refreshed']++;
            } catch (\Throwable $e) {
                $summary['failed']++;
                $isPermanent = ($e instanceof ChannelTokenException && $e->permanent);
                $refreshTokenExpired = $shop->refresh_token_expires_at && $shop->refresh_token_expires_at->isPast();

                if ($isPermanent || $refreshTokenExpired) {
                    $this->shopRepository->markIntegrationError($shop->id, $e->getMessage());
                }

                Log::warning('Shopee refresh token gagal (akan dicoba lagi pada siklus 15 menit berikutnya)', [
                    'shop_id' => $shop->shop_id,
                    'error' => $e->getMessage(),
                    'is_permanent' => $isPermanent,
                ]);
            }
        }

        return $summary;
    }

    private function fetchAndSaveWarehouse(string $shopId, string $accessToken, string $channelId): void
    {
        try {
            $result = $this->client->request(
                'GET',
                '/api/v2/shop/get_warehouse_detail',
                [],
                $accessToken,
                $shopId
            );

            $warehouses = $result['response']['warehouse_list'] ?? [];
            $default = collect($warehouses)->first();
            $locationId = $default['location_id'] ?? null;

            if (! $locationId) {
                return;
            }

            $this->warehouseRepository->saveWarehouseMapping($shopId, $channelId, (string) $locationId, 'DEFAULT');
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch Shopee warehouse at connect', [
                'shop_id' => $shopId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function fetchShopName(string $shopId, string $accessToken): ?string
    {
        try {
            $res = $this->client->request('GET', '/api/v2/shop/get_shop_info', [], $accessToken, $shopId);
            $name = $res['shop_name'] ?? ($res['response']['shop_name'] ?? null);
            $name = is_string($name) ? trim($name) : '';

            return $name !== '' ? $name : null;
        } catch (\Throwable $e) {
            Log::warning("Shopee get_shop_info gagal shop {$shopId}: " . $e->getMessage());

            return null;
        }
    }

    public function syncShopName(ChannelShop $shop): bool
    {
        if (! $shop->access_token) {
            return false;
        }

        $name = $this->fetchShopName($shop->shop_id, $shop->access_token);
        if ($name === null) {
            return false;
        }

        $this->shopRepository->updateShop($shop, ['shop_name' => $name]);

        return true;
    }

    protected function requireShopeeShop(string $id): ChannelShop
    {
        $shop = $this->shopRepository->findByUuid($id);

        $shopeeChannelId = $this->channelRepository->getIdByCode('shopee');
        if (! $shop || $shop->channel_id !== $shopeeChannelId) {
            throw new \Exception('Toko tidak ditemukan');
        }

        return $shop;
    }

    protected function getTokenStatus(ChannelShop $shop): string
    {
        return \Modules\Channel\Support\ChannelTokenStatus::status($shop);
    }
}
