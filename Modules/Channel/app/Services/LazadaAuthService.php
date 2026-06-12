<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Log;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Repositories\ChannelShopRepository;

class LazadaAuthService
{
    public function __construct(
        protected LazadaClient $client,
        protected ChannelShopRepository $shopRepository,
    ) {}

    /**
     * Tukar authorization code → token → simpan akun seller Lazada ke channel_shops.
     * Mengembalikan daftar toko yang berhasil disimpan.
     */
    public function handleCallback(string $code): array
    {
        $token = $this->client->getAccessToken($code);

        $accessToken = $token['access_token'] ?? null;

        if (! $accessToken) {
            $message = $token['message'] ?? ($token['code'] ?? 'Unknown error');
            throw new \Exception('Gagal mengambil access token Lazada: ' . $message);
        }

        $channelId = Channel::where('code', 'lazada')->value('id');
        $refreshToken = $token['refresh_token'] ?? null;
        $account = $token['account'] ?? null;

        $tokenExpiresAt = isset($token['expires_in']) ? now()->addSeconds((int) $token['expires_in']) : null;
        $refreshExpiresAt = isset($token['refresh_expires_in']) ? now()->addSeconds((int) $token['refresh_expires_in']) : null;

        // Lazada bisa mengembalikan banyak toko per negara (country_user_info).
        $sellers = $token['country_user_info'] ?? [];

        if (empty($sellers)) {
            // Fallback: satu akun tanpa rincian per-negara.
            $sellers = [[
                'seller_id' => $token['account_id'] ?? $account ?? 'unknown',
                'short_code' => null,
                'country' => $token['country'] ?? null,
            ]];
        }

        $savedShops = [];

        foreach ($sellers as $seller) {
            $shopId = (string) ($seller['seller_id'] ?? $seller['user_id'] ?? 'unknown');
            $country = $seller['country'] ?? null;
            $shopName = $account
                ? trim($account . ($country ? " ({$country})" : ''))
                : ('Lazada ' . $shopId);

            $this->shopRepository->updateOrCreateShop($shopId, [
                'channel_id' => $channelId,
                'shop_name' => $shopName,
                'shop_cipher' => $seller['short_code'] ?? null,
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_expires_at' => $tokenExpiresAt,
                'refresh_token_expires_at' => $refreshExpiresAt,
                'is_active' => true,
            ]);

            $savedShops[] = ['shop_id' => $shopId, 'shop_name' => $shopName];
        }

        return $savedShops;
    }

    /** Daftar toko Lazada (ringkas, untuk UI manajemen toko). */
    public function getStores(): array
    {
        return $this->shopRepository->getShopsByChannelCode('lazada')
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
        $shop = $this->requireLazadaShop($id);

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
        $shop = $this->requireLazadaShop($id);

        $this->shopRepository->updateShop($shop, [
            'is_active' => false,
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
            'refresh_token_expires_at' => null,
        ]);
    }

    /**
     * Refresh access token satu toko. Respons Lazada flat (access_token/expires_in di top-level).
     */
    public function refreshStoreToken(string $id): array
    {
        $shop = $this->requireLazadaShop($id);

        if (! $shop->refresh_token) {
            throw new \Exception('Refresh token tidak tersedia. Silakan hubungkan ulang toko ini.');
        }

        $response = $this->client->refreshAccessToken($shop->refresh_token);

        $accessToken = $response['access_token'] ?? null;
        if (! $accessToken) {
            throw new \Exception('Gagal refresh token Lazada: ' . ($response['message'] ?? ($response['code'] ?? 'Unknown error')));
        }

        $tokenExpiresAt = isset($response['expires_in']) ? now()->addSeconds((int) $response['expires_in']) : $shop->token_expires_at;
        $refreshExpiresAt = isset($response['refresh_expires_in']) ? now()->addSeconds((int) $response['refresh_expires_in']) : $shop->refresh_token_expires_at;

        $this->shopRepository->updateShop($shop, [
            'access_token' => $accessToken,
            'refresh_token' => $response['refresh_token'] ?? $shop->refresh_token,
            'token_expires_at' => $tokenExpiresAt,
            'refresh_token_expires_at' => $refreshExpiresAt,
        ]);

        return [
            'shop_id' => $shop->shop_id,
            'shop_name' => $shop->shop_name,
            'token_expires_at' => $tokenExpiresAt?->toIso8601String(),
        ];
    }

    /**
     * Refresh semua toko Lazada aktif yang token-nya akan kedaluwarsa dalam $hours jam.
     * Per-toko di-try/catch agar satu kegagalan tidak menghentikan sisanya (dipakai scheduler).
     *
     * @return array{refreshed: int, failed: int, skipped: int}
     */
    public function refreshExpiringTokens(int $hours = 48): array
    {
        $summary = ['refreshed' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($this->shopRepository->getShopsByChannelCode('lazada') as $shop) {
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
                Log::warning('Lazada refresh token gagal', ['shop_id' => $shop->shop_id, 'error' => $e->getMessage()]);
            }
        }

        return $summary;
    }

    protected function requireLazadaShop(string $id): ChannelShop
    {
        $shop = $this->shopRepository->findByUuid($id);

        $lazadaChannelId = Channel::where('code', 'lazada')->value('id');
        if (! $shop || $shop->channel_id !== $lazadaChannelId) {
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

        // Perbandingan eksplisit (Carbon 3: diffInHours bertanda negatif utk tanggal depan).
        if ($shop->token_expires_at->lt(now()->addHours(24))) {
            return 'expiring_soon';
        }

        return 'active';
    }
}
