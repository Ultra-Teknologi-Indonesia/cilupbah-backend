<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Channel\Exceptions\TokenExpiredException;

class LazadaClient
{

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

    protected function throttle(): void
    {
        $limit = config('channel.api_rate_limit_per_second', 8);

        if (! RateLimiter::attempt('lazada-api', $limit, fn () => null, 1)) {
            $wait = RateLimiter::availableIn('lazada-api');
            usleep((int) ($wait * 1_000_000) + 50_000);
        }
    }

    public function getAuthUrl(string $redirectUri, string $state = '', bool $forceAuth = false): string
    {
        if (trim($redirectUri) === '') {
            throw new \RuntimeException('LAZADA_REDIRECT_URI belum dikonfigurasi. Callback URL wajib diisi agar OAuth authorize tidak gagal "Missing parameter".');
        }

        // client_id (app_key) sudah dijamin non-empty oleh konstruktor.
        $queries = [
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'client_id' => $this->appKey,
        ];

        if ($forceAuth) {
            $queries['force_auth'] = 'true';
        }

        if ($state !== '') {
            $queries['state'] = $state;
        }

        return $this->authUrl . '/oauth/authorize?' . http_build_query($queries);
    }

    public function getAccessToken(string $code): array
    {
        return $this->signedGet('/auth/token/create', ['code' => $code]);
    }

    public function refreshAccessToken(string $refreshToken): array
    {
        return $this->signedGet('/auth/token/refresh', ['refresh_token' => $refreshToken]);
    }

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
