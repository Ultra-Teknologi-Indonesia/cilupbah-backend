<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Channel\Exceptions\TokenExpiredException;
use Modules\Channel\Helpers\ShopeeSignature;

class ShopeeClient
{

    protected const TOKEN_ERROR_CODES = ['error_auth', 'invalid_access_token', 'error_token_expired', 'access_token_error'];

    protected string $partnerId;
    protected string $partnerKey;
    protected string $host;

    public function __construct()
    {
        $this->partnerId = (string) config('services.shopee.partner_id');
        $this->partnerKey = (string) config('services.shopee.partner_key');
        $this->host = rtrim((string) config('services.shopee.host', 'https://partner.shopeemobile.com'), '/');

        if (! $this->partnerId || ! $this->partnerKey) {
            throw new \RuntimeException('Kredensial Shopee belum dikonfigurasi. Set SHOPEE_PARTNER_ID dan SHOPEE_PARTNER_KEY.');
        }
    }

    public function getAuthUrl(string $redirectUri, string $state = ''): string
    {
        $path = '/api/v2/shop/auth_partner';
        $timestamp = time();
        $sign = ShopeeSignature::publicSign($this->partnerId, $path, $timestamp, $this->partnerKey);

        if ($state !== '') {
            $separator = str_contains($redirectUri, '?') ? '&' : '?';
            $redirectUri .= $separator . 'state=' . urlencode($state);
        }

        $query = http_build_query([
            'partner_id' => $this->partnerId,
            'timestamp' => $timestamp,
            'sign' => $sign,
            'redirect' => $redirectUri,
        ]);

        return $this->host . $path . '?' . $query;
    }

    public function getAccessToken(string $code, string $shopId): array
    {
        return $this->publicPost('/api/v2/auth/token/get', [
            'code' => $code,
            'shop_id' => (int) $shopId,
            'partner_id' => (int) $this->partnerId,
        ]);
    }

    public function refreshAccessToken(string $refreshToken, string $shopId): array
    {
        return $this->publicPost('/api/v2/auth/access_token/get', [
            'refresh_token' => $refreshToken,
            'shop_id' => (int) $shopId,
            'partner_id' => (int) $this->partnerId,
        ]);
    }

    public function request(string $method, string $apiPath, array $params, string $accessToken, string $shopId): array
    {
        $timestamp = time();
        $sign = ShopeeSignature::shopSign($this->partnerId, $apiPath, $timestamp, $accessToken, $shopId, $this->partnerKey);

        $common = [
            'partner_id' => $this->partnerId,
            'timestamp' => $timestamp,
            'access_token' => $accessToken,
            'shop_id' => (int) $shopId,
            'sign' => $sign,
        ];

        $this->throttle();

        if (strtoupper($method) === 'GET') {
            $url = $this->host . $apiPath . '?' . http_build_query(array_merge($common, $params));
            $response = Http::get($url);
        } else {
            $url = $this->host . $apiPath . '?' . http_build_query($common);
            $response = Http::asJson()->post($url, $params);
        }

        $data = $response->json() ?? [];
        $error = (string) ($data['error'] ?? '');

        if ($error !== '') {
            Log::error('Shopee API Error', [
                'path' => $apiPath,
                'error' => $error,
                'message' => $data['message'] ?? null,
                'result_list' => $data['response']['result_list'] ?? null,
            ]);

            if (in_array($error, self::TOKEN_ERROR_CODES, true) || str_contains(strtolower($error), 'token')) {
                throw new TokenExpiredException($shopId, $data['message'] ?? 'Shopee access token expired');
            }

            throw new \Exception('Shopee API Error: ' . $this->describeError($data, $error));
        }

        return $data;
    }

    /**
     * Batch endpoints answer with a generic "All failed" message and put the real
     * reason inside response.result_list. Surface that so the caller sees why.
     */
    protected function describeError(array $data, string $error): string
    {
        $message = (string) ($data['message'] ?? $error);

        $row = $data['response']['result_list'][0] ?? [];
        $detail = $row['fail_message'] ?? $row['fail_error'] ?? null;

        return $detail ? $message . ' (' . $detail . ')' : $message;
    }

    public function requestBinary(string $apiPath, array $params, string $accessToken, string $shopId): array
    {
        $timestamp = time();
        $sign = ShopeeSignature::shopSign($this->partnerId, $apiPath, $timestamp, $accessToken, $shopId, $this->partnerKey);

        $common = [
            'partner_id' => $this->partnerId,
            'timestamp' => $timestamp,
            'access_token' => $accessToken,
            'shop_id' => (int) $shopId,
            'sign' => $sign,
        ];

        $this->throttle();

        $url = $this->host . $apiPath . '?' . http_build_query($common);
        $response = Http::asJson()->post($url, $params);

        $contentType = strtolower((string) $response->header('Content-Type'));
        $rawBody = (string) $response->body();

        $looksJson = str_contains($contentType, 'application/json')
            || (str_starts_with(ltrim($rawBody), '{') && json_decode($rawBody) !== null);

        if ($looksJson) {
            $data = $response->json() ?? [];
            $error = (string) ($data['error'] ?? '');

            if ($error !== '') {
                Log::error('Shopee API Error', [
                    'path' => $apiPath,
                    'error' => $error,
                    'message' => $data['message'] ?? null,
                    'result_list' => $data['response']['result_list'] ?? null,
                ]);

                if (in_array($error, self::TOKEN_ERROR_CODES, true) || str_contains(strtolower($error), 'token')) {
                    throw new TokenExpiredException($shopId, $data['message'] ?? 'Shopee access token expired');
                }

                throw new \Exception('Shopee API Error: ' . $this->describeError($data, $error));
            }

            return $data;
        }

        return [
            'binary' => true,
            'content_type' => $contentType ?: 'application/octet-stream',
            'content' => $rawBody,
            'size' => strlen($rawBody),
        ];
    }

    public function uploadImage(string $contents, string $filename = 'image.jpg'): ?string
    {
        $path = '/api/v2/media_space/upload_image';
        $timestamp = time();
        $sign = ShopeeSignature::publicSign($this->partnerId, $path, $timestamp, $this->partnerKey);

        $this->throttle();

        $url = $this->host . $path . '?' . http_build_query([
            'partner_id' => $this->partnerId,
            'timestamp' => $timestamp,
            'sign' => $sign,
        ]);

        $response = Http::attach('image', $contents, $filename)->post($url);
        $data = $response->json() ?? [];

        if (! empty($data['error'])) {
            Log::error('Shopee upload_image error', [
                'error' => $data['error'],
                'message' => $data['message'] ?? null,
            ]);

            return null;
        }

        return $data['response']['image_info']['image_id']
            ?? $data['response']['image_info_list'][0]['image_info']['image_id']
            ?? null;
    }

    protected function publicPost(string $path, array $body): array
    {
        $timestamp = time();
        $sign = ShopeeSignature::publicSign($this->partnerId, $path, $timestamp, $this->partnerKey);

        $this->throttle();

        $url = $this->host . $path . '?' . http_build_query([
            'partner_id' => $this->partnerId,
            'timestamp' => $timestamp,
            'sign' => $sign,
        ]);

        $response = Http::asJson()->post($url, $body);
        $data = $response->json() ?? [];

        if (! empty($data['error'])) {
            Log::error('Shopee API Error', [
                'path' => $path,
                'error' => $data['error'] ?? null,
                'message' => $data['message'] ?? null,
            ]);
        }

        return $data;
    }

    protected function throttle(): void
    {
        $limit = config('channel.api_rate_limit_per_second', 8);

        if (! RateLimiter::attempt('shopee-api', $limit, fn () => null, 1)) {
            $wait = RateLimiter::availableIn('shopee-api');
            usleep((int) ($wait * 1_000_000) + 50_000);
        }
    }
}
