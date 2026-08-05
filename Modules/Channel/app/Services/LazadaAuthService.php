<?php

namespace Modules\Channel\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Exceptions\ChannelTokenException;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Repositories\ChannelRepository;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Support\ChannelReauthCopy;

class LazadaAuthService
{
    public function __construct(
        protected LazadaClient $client,
        protected ChannelShopRepository $shopRepository,
        protected ChannelRepository $channelRepository,
    ) {}

    public function handleCallback(string $code): array
    {
        $token = $this->client->getAccessToken($code);

        $accessToken = $token['access_token'] ?? null;

        if (! $accessToken) {
            $message = $token['message'] ?? ($token['code'] ?? 'Unknown error');
            throw new \Exception('Gagal mengambil access token Lazada: ' . $message);
        }

        $channelId = $this->channelRepository->getIdByCode('lazada');
        $refreshToken = $token['refresh_token'] ?? null;
        $account = $token['account'] ?? null;

        $tokenExpiresAt = isset($token['expires_in']) ? now()->addSeconds((int) $token['expires_in']) : null;
        $refreshExpiresAt = isset($token['refresh_expires_in']) ? now()->addSeconds((int) $token['refresh_expires_in']) : null;

        $sellers = $token['country_user_info'] ?? [];

        if (empty($sellers)) {

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

    public function getStoreModel(string $id): ChannelShop
    {
        return $this->requireLazadaShop($id);
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

    public function refreshStoreToken(string $id): array
    {
        $shop = $this->requireLazadaShop($id);

        if (! $shop->refresh_token) {
            throw new ChannelTokenException(
                ChannelReauthCopy::missingRefreshToken('lazada'),
                permanent: true,
                channelCode: 'lazada',
                rawMessage: 'Refresh token tidak tersedia',
            );
        }

        try {
            $response = $this->client->refreshAccessToken($shop->refresh_token);
        } catch (ConnectionException $e) {
            throw new ChannelTokenException(
                ChannelReauthCopy::refreshFailure('lazada', false),
                permanent: false,
                channelCode: 'lazada',
                rawMessage: $e->getMessage(),
            );
        }

        $accessToken = $response['access_token'] ?? null;
        if (! $accessToken) {
            $raw = (string) ($response['message'] ?? ($response['code'] ?? 'Unknown error'));
            $permanent = ChannelReauthCopy::isPermanentFailure($raw);

            Log::warning('Lazada refresh token gagal', [
                'shop_id' => $shop->shop_id,
                'raw' => $raw,
                'permanent' => $permanent,
            ]);

            throw new ChannelTokenException(
                ChannelReauthCopy::refreshFailure('lazada', $permanent),
                permanent: $permanent,
                channelCode: 'lazada',
                rawMessage: $raw,
            );
        }

        $tokenExpiresAt = isset($response['expires_in']) ? now()->addSeconds((int) $response['expires_in']) : $shop->token_expires_at;
        $refreshExpiresAt = isset($response['refresh_expires_in']) ? now()->addSeconds((int) $response['refresh_expires_in']) : $shop->refresh_token_expires_at;

        Log::info('Lazada token refreshed', [
            'shop_id' => $shop->shop_id,
            'refresh_expires_in' => $response['refresh_expires_in'] ?? null,
            'refresh_token_expires_at' => $refreshExpiresAt?->toIso8601String(),
            'refresh_token_rotated' => isset($response['refresh_token'])
                && $response['refresh_token'] !== $shop->refresh_token,
        ]);

        $this->shopRepository->updateShop($shop, [
            'access_token' => $accessToken,
            'refresh_token' => $response['refresh_token'] ?? $shop->refresh_token,
            'token_expires_at' => $tokenExpiresAt,
            'refresh_token_expires_at' => $refreshExpiresAt,
        ]);

        $this->shopRepository->markIntegrationHealthy($id);

        return [
            'shop_id' => $shop->shop_id,
            'shop_name' => $shop->shop_name,
            'token_expires_at' => $tokenExpiresAt?->toIso8601String(),
        ];
    }

    public function refreshExpiringTokens(int $hours = 48): array
    {
        $summary = ['refreshed' => 0, 'failed' => 0, 'skipped' => 0, 'transient' => 0];

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
            } catch (ChannelTokenException $e) {
                if ($e->permanent) {
                    $summary['failed']++;
                    $this->shopRepository->markIntegrationError($shop->id, $e->getMessage());
                    Log::warning('Lazada refresh token gagal permanen', ['shop_id' => $shop->shop_id, 'error' => $e->getMessage(), 'raw' => $e->rawMessage]);
                } else {
                    $summary['transient']++;
                    Log::warning('Lazada refresh token gagal sementara, akan dicoba ulang', ['shop_id' => $shop->shop_id, 'raw' => $e->rawMessage]);
                }
            } catch (\Throwable $e) {

                $summary['transient']++;
                Log::warning('Lazada refresh token error tak terduga', ['shop_id' => $shop->shop_id, 'error' => $e->getMessage()]);
            }
        }

        return $summary;
    }

    protected function requireLazadaShop(string $id): ChannelShop
    {
        $shop = $this->shopRepository->findByUuid($id);

        $lazadaChannelId = $this->channelRepository->getIdByCode('lazada');
        if (! $shop || $shop->channel_id !== $lazadaChannelId) {
            throw new \Exception('Toko tidak ditemukan');
        }

        return $shop;
    }

    protected function getTokenStatus(ChannelShop $shop): string
    {
        return \Modules\Channel\Support\ChannelTokenStatus::status($shop);
    }
}
