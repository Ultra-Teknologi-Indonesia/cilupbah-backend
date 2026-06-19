<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Log;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Repositories\ChannelShopRepository;

class ShopeeAuthService
{
    /** Refresh token Shopee berlaku 30 hari. */
    private const REFRESH_TOKEN_TTL_SECONDS = 2592000;

    public function __construct(
        protected ShopeeClient $client,
        protected ChannelShopRepository $shopRepository,
    ) {}

    /**
     * Tukar code+shop_id menjadi token dan simpan toko Shopee.
     *
     * @return array{shop_id: string, shop_name: string}
     */
    public function handleCallback(string $code, string $shopId): array
    {
        $token = $this->client->getAccessToken($code, $shopId);

        $accessToken = $token['access_token'] ?? null;

        if (! $accessToken) {
            $message = $token['message'] ?? ($token['error'] ?? 'Unknown error');
            throw new \Exception('Gagal mengambil access token Shopee: ' . $message);
        }

        $channelId = Channel::where('code', 'shopee')->value('id');

        // Shopee memakai 'expire_in' (detik, ±4 jam) untuk access token.
        $expireIn = (int) ($token['expire_in'] ?? $token['expires_in'] ?? 0);
        $tokenExpiresAt = $expireIn > 0 ? now()->addSeconds($expireIn) : null;
        $refreshExpiresAt = now()->addSeconds(self::REFRESH_TOKEN_TTL_SECONDS);

        $shopName = 'Shopee ' . $shopId;

        $this->shopRepository->updateOrCreateShop($shopId, [
            'channel_id' => $channelId,
            'shop_name' => $shopName,
            'access_token' => $accessToken,
            'refresh_token' => $token['refresh_token'] ?? null,
            'token_expires_at' => $tokenExpiresAt,
            'refresh_token_expires_at' => $refreshExpiresAt,
            'is_active' => true,
        ]);

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

        if (! $shop->refresh_token) {
            throw new \Exception('Refresh token tidak tersedia. Silakan hubungkan ulang toko ini.');
        }

        $response = $this->client->refreshAccessToken($shop->refresh_token, $shop->shop_id);

        $accessToken = $response['access_token'] ?? null;
        if (! $accessToken) {
            throw new \Exception('Gagal refresh token Shopee: ' . ($response['message'] ?? ($response['error'] ?? 'Unknown error')));
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

    /**
     * Refresh proaktif access token yang mendekati kedaluwarsa.
     * Access token Shopee hanya 4 jam → dijadwalkan per jam dengan window pendek (default 2 jam).
     *
     * @return array{refreshed: int, failed: int, skipped: int}
     */
    public function refreshExpiringTokens(int $hours = 2): array
    {
        $summary = ['refreshed' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($this->shopRepository->getShopsByChannelCode('shopee') as $shop) {
            // Refresh token Shopee berlaku 30 hari; jika sudah lewat, toko harus dihubungkan ulang.
            $refreshTokenValid = $shop->refresh_token
                && (! $shop->refresh_token_expires_at || $shop->refresh_token_expires_at->isFuture());

            $needsRefresh = $shop->is_active
                && $refreshTokenValid
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
                Log::warning('Shopee refresh token gagal', ['shop_id' => $shop->shop_id, 'error' => $e->getMessage()]);
            }
        }

        return $summary;
    }

    protected function requireShopeeShop(string $id): ChannelShop
    {
        $shop = $this->shopRepository->findByUuid($id);

        $shopeeChannelId = Channel::where('code', 'shopee')->value('id');
        if (! $shop || $shop->channel_id !== $shopeeChannelId) {
            throw new \Exception('Toko tidak ditemukan');
        }

        return $shop;
    }

    protected function getTokenStatus(ChannelShop $shop): string
    {
        if (! $shop->is_active || ! $shop->access_token) {
            return 'disconnected';
        }

        if (! $shop->token_expires_at) {
            return 'active';
        }

        if ($shop->token_expires_at->isPast()) {
            return 'expired';
        }

        // Access token Shopee hanya 4 jam; anggap "expiring_soon" bila < 1 jam.
        if ($shop->token_expires_at->lt(now()->addHour())) {
            return 'expiring_soon';
        }

        return 'active';
    }
}
