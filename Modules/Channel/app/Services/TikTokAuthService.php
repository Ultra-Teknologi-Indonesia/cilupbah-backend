<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Log;
use Modules\Channel\Repositories\ChannelRepository;
use Modules\Channel\Repositories\ChannelShopRepository;

class TikTokAuthService
{
    protected TikTokClient $client;
    protected ChannelShopRepository $shopRepository;
    protected ChannelRepository $channelRepository;

    public function __construct(TikTokClient $client, ChannelShopRepository $shopRepository, ChannelRepository $channelRepository)
    {
        $this->client = $client;
        $this->shopRepository = $shopRepository;
        $this->channelRepository = $channelRepository;
    }

    public function handleCallback(string $code, string $redirectUri): array
    {
        $tokenData = $this->client->getAccessToken($code, $redirectUri);

        if (isset($tokenData['code']) && $tokenData['code'] !== 0) {
            throw new \Exception('Failed to get access token: ' . json_encode($tokenData));
        }

        $data = $tokenData['data'] ?? [];
        $accessToken = $data['access_token'] ?? null;

        if (!$accessToken) {
            throw new \Exception('No access token received: ' . json_encode($tokenData));
        }

        $shopsResponse = $this->client->getAuthorizedShops($accessToken);
        if (isset($shopsResponse['code']) && $shopsResponse['code'] !== 0) {
            throw new \Exception('Failed to fetch authorized shops: ' . json_encode($shopsResponse));
        }

        $shops = $shopsResponse['data']['shops'] ?? [];
        $savedShops = [];

        foreach ($shops as $shop) {
            $shopId = $shop['id'] ?? $shop['shop_id'] ?? 'unknown';
            $shopCipher = $shop['cipher'] ?? $shop['shop_cipher'] ?? null;
            $shopName = $shop['name'] ?? $shop['shop_name'] ?? null;

            $this->shopRepository->updateOrCreateShop(
                $shopId,
                [
                    'channel_id' => $this->channelRepository->getIdByCode('tiktok'),
                    'shop_name' => $shopName,
                    'shop_cipher' => $shopCipher,
                    'access_token' => $accessToken,
                    'refresh_token' => $data['refresh_token'] ?? null,
                    'token_expires_at' => isset($data['access_token_expire_in']) ? now()->addSeconds($data['access_token_expire_in']) : null,
                    'refresh_token_expires_at' => isset($data['refresh_token_expire_in']) ? now()->addSeconds($data['refresh_token_expire_in']) : null,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            $savedShops[] = ['shop_id' => $shopId, 'shop_name' => $shopName];
        }

        return $savedShops;
    }

    public function getStores(): array
    {
        $shops = $this->shopRepository->getAllTikTokShops();

        return $shops->map(function ($shop) {
            return [
                'id' => $shop->id,
                'shop_id' => $shop->shop_id,
                'shop_name' => $shop->shop_name,
                'is_active' => $shop->is_active,
                'token_status' => $this->getTokenStatus($shop),
                'connected_at' => $shop->created_at,
                'updated_at' => $shop->updated_at,
            ];
        })->toArray();
    }

    public function getStoreDetail(string $id): array
    {
        $shop = $this->shopRepository->findById($id);
        if (!$shop) {
            throw new \Exception('Toko tidak ditemukan');
        }

        return [
            'id' => $shop->id,
            'shop_id' => $shop->shop_id,
            'shop_name' => $shop->shop_name,
            'shop_cipher' => $shop->shop_cipher,
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
        $shop = $this->shopRepository->findById($id);
        if (!$shop) {
            throw new \Exception('Toko tidak ditemukan');
        }

        $this->shopRepository->disconnectShop($id);
    }

    public function refreshStoreToken(string $id): array
    {
        $shop = $this->shopRepository->findById($id);
        if (!$shop) {
            throw new \Exception('Toko tidak ditemukan');
        }

        if (!$shop->refresh_token) {
            throw new \Exception('Refresh token tidak tersedia. Silakan hubungkan ulang toko ini.');
        }

        $response = $this->client->refreshAccessToken($shop->refresh_token);

        if (isset($response['code']) && $response['code'] !== 0) {
            throw new \Exception('Gagal refresh token: ' . ($response['message'] ?? json_encode($response)));
        }

        $data = $response['data'] ?? [];

        $this->shopRepository->updateTokens($id, [
            'access_token' => $data['access_token'] ?? $shop->access_token,
            'refresh_token' => $data['refresh_token'] ?? $shop->refresh_token,
            'token_expires_at' => isset($data['access_token_expire_in']) ? now()->addSeconds($data['access_token_expire_in']) : $shop->token_expires_at,
            'refresh_token_expires_at' => isset($data['refresh_token_expire_in']) ? now()->addSeconds($data['refresh_token_expire_in']) : $shop->refresh_token_expires_at,
            'updated_at' => now(),
        ]);

        $this->shopRepository->markIntegrationHealthy($id);

        return [
            'shop_id' => $shop->shop_id,
            'shop_name' => $shop->shop_name,
            'token_expires_at' => isset($data['access_token_expire_in']) ? now()->addSeconds($data['access_token_expire_in'])->toIso8601String() : null,
        ];
    }

    public function refreshExpiringTokens(int $hours = 24): array
    {
        $summary = ['refreshed' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($this->shopRepository->getShopsByChannelCode('tiktok') as $shop) {
            $needsRefresh = $shop->is_active
                && $shop->refresh_token
                && $shop->token_expires_at
                && $shop->token_expires_at->lte(now()->addHours($hours));

            if (! $needsRefresh) {
                $summary['skipped']++;
                continue;
            }

            try {
                $this->refreshStoreToken($shop->id);
                $summary['refreshed']++;
            } catch (\Throwable $e) {
                $summary['failed']++;
                $this->shopRepository->markIntegrationError($shop->id, $e->getMessage());
                Log::warning('TikTok refresh token gagal', ['shop_id' => $shop->shop_id, 'error' => $e->getMessage()]);
            }
        }

        return $summary;
    }

    protected function getTokenStatus($shop): string
    {
        if (!$shop->is_active || !$shop->access_token) {
            return 'disconnected';
        }

        if (!$shop->token_expires_at) {
            return 'active';
        }

        if ($shop->token_expires_at->isPast()) {
            return 'expired';
        }

        if ($shop->token_expires_at->diffInHours(now()) < 24) {
            return 'expiring_soon';
        }

        return 'active';
    }
}
