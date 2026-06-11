<?php

namespace Modules\Channel\Services;

use Modules\Channel\Models\Channel;
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
}
