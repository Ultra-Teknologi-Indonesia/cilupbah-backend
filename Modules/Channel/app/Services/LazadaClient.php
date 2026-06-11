<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Channel\Exceptions\TokenExpiredException;

/**
 * Klien Lazada Open Platform: OAuth (auth.lazada.com) + business API (gateway regional).
 * Semua panggilan memakai signature HMAC-SHA256 (sha256) standar Lazada.
 *
 * Ref: https://open.lazada.com/apps/doc/doc?nodeId=10450&docId=108069
 */
class LazadaClient
{
    /** Kode error Lazada yang berarti access token tidak valid/kedaluwarsa. */
    protected const TOKEN_ERROR_CODES = ['IllegalAccessToken', 'InvalidAccessToken', 'AppCallLimit.TokenExpired'];

    protected string $appKey;
    protected string $appSecret;
    protected string $authUrl;
    protected string $baseUrl;

    public function __construct()
    {
        $this->appKey = (string) config('services.lazada.app_key');
        $this->appSecret = (string) config('services.lazada.app_secret');
        $this->authUrl = rtrim((string) config('services.lazada.auth_url', 'https://auth.lazada.com'), '/');
        $this->baseUrl = rtrim((string) config('services.lazada.base_url', 'https://api.lazada.co.id/rest'), '/');

        if (! $this->appKey || ! $this->appSecret) {
            throw new \RuntimeException('Kredensial Lazada belum dikonfigurasi. Set LAZADA_APP_KEY dan LAZADA_APP_SECRET.');
        }
    }

    /**
     * Panggilan business API tertandatangani ke gateway regional (LAZADA_BASE_URL).
     * $params = query/system params; access token disertakan sebagai param 'access_token'.
     * Error token (IllegalAccessToken dsb) → TokenExpiredException agar caller bisa refresh+retry.
     */
    public function request(string $method, string $apiPath, array $params = [], ?string $accessToken = null): array
    {
        $params = array_merge($params, [
            'app_key' => $this->appKey,
            'sign_method' => 'sha256',
            'timestamp' => (string) (int) (microtime(true) * 1000),
        ]);

        if ($accessToken) {
            $params['access_token'] = $accessToken;
        }

        $params['sign'] = $this->generateSign($apiPath, $params);

        $this->throttle();

        $url = $this->baseUrl . $apiPath;
        $response = strtoupper($method) === 'GET'
            ? Http::get($url, $params)
            : Http::asForm()->post($url, $params);

        $data = $response->json() ?? [];

        // Sukses Lazada: code === '0'. Selain itu = error.
        if (($data['code'] ?? '0') !== '0') {
            Log::error('Lazada API Error', [
                'path' => $apiPath,
                'code' => $data['code'] ?? null,
                'message' => $data['message'] ?? null,
            ]);

            if (in_array((string) ($data['code'] ?? ''), self::TOKEN_ERROR_CODES, true)) {
                throw new TokenExpiredException('lazada', $data['message'] ?? 'Lazada access token expired');
            }

            throw new \Exception('Lazada API Error: ' . ($data['message'] ?? ($data['code'] ?? 'Unknown error')));
        }

        return $data;
    }

    /**
     * Throttle sesuai CHANNEL_API_RATE_LIMIT_PER_SECOND (pola TikTokClient).
     */
    protected function throttle(): void
    {
        $limit = config('channel.api_rate_limit_per_second', 8);

        if (! RateLimiter::attempt('lazada-api', $limit, fn () => null, 1)) {
            $wait = RateLimiter::availableIn('lazada-api');
            usleep((int) ($wait * 1_000_000) + 50_000);
        }
    }

    /**
     * URL yang dibuka seller untuk memberi otorisasi. Setelah setuju, Lazada redirect
     * ke redirect_uri dengan query ?code=<authorization_code>.
     */
    public function getAuthUrl(string $redirectUri, string $state = ''): string
    {
        $queries = [
            'response_type' => 'code',
            'force_auth' => 'true',
            'redirect_uri' => $redirectUri,
            'client_id' => $this->appKey,
        ];

        if ($state !== '') {
            $queries['state'] = $state;
        }

        return $this->authUrl . '/oauth/authorize?' . http_build_query($queries);
    }

    /**
     * Tukar authorization code menjadi access/refresh token.
     */
    public function getAccessToken(string $code): array
    {
        return $this->signedGet('/auth/token/create', ['code' => $code]);
    }

    /**
     * Perpanjang access token memakai refresh token.
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        return $this->signedGet('/auth/token/refresh', ['refresh_token' => $refreshToken]);
    }

    /**
     * Panggilan GET tertandatangani ke endpoint sistem Lazada (/rest + apiPath).
     */
    protected function signedGet(string $apiPath, array $params): array
    {
        $params = array_merge($params, [
            'app_key' => $this->appKey,
            'sign_method' => 'sha256',
            'timestamp' => (string) (int) (microtime(true) * 1000),
        ]);

        $params['sign'] = $this->generateSign($apiPath, $params);

        $response = Http::asForm()->get($this->authUrl . '/rest' . $apiPath, $params);

        return $response->json() ?? [];
    }

    /**
     * Signature Lazada: HMAC-SHA256 atas (apiPath + gabungan key+value terurut),
     * key = app_secret, hasil hex uppercase. Param 'sign' tidak ikut ditandatangani.
     */
    public function generateSign(string $apiPath, array $params): string
    {
        unset($params['sign']);
        ksort($params);

        $payload = $apiPath;
        foreach ($params as $key => $value) {
            $payload .= $key . $value;
        }

        return strtoupper(hash_hmac('sha256', $payload, $this->appSecret));
    }
}
