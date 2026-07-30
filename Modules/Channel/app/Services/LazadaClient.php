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
            ? Http::timeout(30)->connectTimeout(15)->get($url, $params)
            : Http::asForm()->timeout(30)->connectTimeout(15)->post($url, $params);

        $data = $response->json() ?? [];

        if ($response->failed()) {
            $message = is_array($data) ? ($data['message'] ?? $response->body()) : $response->body();

            Log::error('Lazada API HTTP Error', [
                'path' => $apiPath,
                'status' => $response->status(),
                'message' => $message,
            ]);

            throw new \Exception('Lazada API Error: ' . $message);
        }

        if (($data['code'] ?? '0') !== '0') {
            $detail = $this->formatErrorDetail($data['detail'] ?? $data['error_detail'] ?? null);

            Log::error('Lazada API Error', [
                'path' => $apiPath,
                'code' => $data['code'] ?? null,
                'message' => $data['message'] ?? null,
                'detail' => $detail !== '' ? $detail : null,
                'request_id' => $data['request_id'] ?? null,
            ]);

            if (in_array((string) ($data['code'] ?? ''), self::TOKEN_ERROR_CODES, true)) {
                throw new TokenExpiredException('lazada', $data['message'] ?? 'Lazada access token expired');
            }

            $reason = trim((string) ($data['message'] ?? $data['code'] ?? 'Unknown error'));
            if ($detail !== '' && ! str_contains($reason, $detail)) {
                $reason = $reason !== '' ? $reason . ' — ' . $detail : $detail;
            }

            throw new \Exception('Lazada API Error: ' . $reason);
        }

        return $data;
    }

    protected function formatErrorDetail($detail): string
    {
        if (empty($detail)) {
            return '';
        }

        if (is_string($detail)) {
            return trim($detail);
        }

        if (! is_array($detail)) {
            return '';
        }

        $parts = [];
        foreach ($detail as $item) {
            if (! is_array($item)) {
                $parts[] = trim((string) $item);
                continue;
            }

            $field = $item['field'] ?? $item['Field'] ?? null;
            $message = $item['message'] ?? $item['Message'] ?? null;

            if ($field !== null && $message !== null) {
                $parts[] = "{$field}: {$message}";
            } elseif ($message !== null) {
                $parts[] = (string) $message;
            } else {
                $parts[] = json_encode($item, JSON_UNESCAPED_UNICODE);
            }
        }

        return trim(implode('; ', array_filter($parts, fn ($p) => $p !== '')));
    }

    public function uploadMultipart(string $apiPath, array $params, string $fileField, string $fileContents, string $filename, ?string $accessToken = null): array
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

        $response = Http::attach($fileField, $fileContents, $filename)->timeout(30)->connectTimeout(15)->post($this->baseUrl . $apiPath, $params);
        $data = $response->json() ?? [];

        if (($data['code'] ?? '0') !== '0') {
            throw new \Exception('Lazada API Error: ' . ($data['message'] ?? $data['code'] ?? 'Unknown error'));
        }

        return $data;
    }

    protected function throttle(): void
    {
        $limit = config('channel.api_rate_limit_per_second', 8);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            if (RateLimiter::attempt('lazada-api', $limit, fn () => null, 1)) {
                return;
            }

            $wait = RateLimiter::availableIn('lazada-api');
            usleep((int) ($wait * 1_000_000) + 50_000);
        }
    }

    public function getAuthUrl(string $redirectUri, string $state = '', bool $forceAuth = false): string
    {
        if (trim($redirectUri) === '') {
            throw new \RuntimeException('LAZADA_REDIRECT_URI belum dikonfigurasi. Callback URL wajib diisi agar OAuth authorize tidak gagal "Missing parameter".');
        }

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

        $response = Http::asForm()->timeout(30)->connectTimeout(15)->get($this->authUrl . '/rest' . $apiPath, $params);

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
