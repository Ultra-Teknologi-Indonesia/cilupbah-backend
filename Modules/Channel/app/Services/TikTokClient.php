<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TikTokClient
{
    protected string $appKey;
    protected string $appSecret;
    protected string $baseUrl;

    public function __construct()
    {
        $this->appKey = config('services.tiktok.app_key');
        $this->appSecret = config('services.tiktok.app_secret');
        $this->baseUrl = config('services.tiktok.base_url', 'https://open-api.tiktokglobalshop.com');

        if (!$this->appKey || !$this->appSecret) {
            throw new \RuntimeException('TikTok credentials are not configured. Set TIKTOK_APP_KEY and TIKTOK_APP_SECRET.');
        }
    }

    public function generateSignature(string $path, array $queries, $body = null, bool $isMultipart = false, string $method = 'POST'): string
    {
        $contentType = $isMultipart ? 'multipart/form-data' : 'application/json';
        
        if (!$isMultipart && empty($body) && strtoupper($method) !== 'GET') {
            $body = '{}';
        }

        return \Modules\Channel\Helpers\TikTokSignature::generate($path, $queries, $body, $this->appSecret, $contentType);
    }

    public function request(string $method, string $path, array $queries = [], array $body = [], ?string $accessToken = null, array $files = [])
    {
        $queries['app_key'] = $this->appKey;
        $queries['timestamp'] = time();

        if ($accessToken) {
            $queries['access_token'] = $accessToken;
        }

        $isMultipart = !empty($files);
        $queries['sign'] = $this->generateSignature($path, $queries, empty($body) ? null : $body, $isMultipart, $method);

        $url = $this->baseUrl . $path;

        $queryString = http_build_query($queries);
        $fullUrl = $url . '?' . $queryString;

        $requestMethod = strtolower($method);

        $headers = [];
        if ($accessToken) {
            $headers['x-tts-access-token'] = $accessToken;
        }

        $http = Http::withHeaders($headers);

        if ($isMultipart) {
            foreach ($files as $name => $fileData) {
                $http = $http->attach($name, $fileData['contents'], $fileData['filename']);
            }
            $response = $http->post($fullUrl, $body);
        } elseif ($requestMethod === 'get') {
            $response = $http->get($fullUrl);
        } elseif ($requestMethod === 'put') {
            $jsonBody = empty($body) ? '{}' : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $response = $http->withBody($jsonBody, 'application/json')->put($fullUrl);
        } elseif ($requestMethod === 'delete') {
            $jsonBody = empty($body) ? '{}' : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $response = $http->withBody($jsonBody, 'application/json')->delete($fullUrl);
        } else {
            $jsonBody = empty($body) ? '{}' : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $response = $http->withBody($jsonBody, 'application/json')->post($fullUrl);
        }

        $data = $response->json();

        if (isset($data['code']) && $data['code'] !== 0) {
            Log::error('TikTok API Error', [
                'url' => $fullUrl,
                'body' => $body,
                'response' => $data,
            ]);

            throw new \Exception("TikTok API Error: " . ($data['message'] ?? 'Unknown error'));
        }

        return $data;
    }

    public function getAuthUrl(string $redirectUri, string $state = ''): string
    {
        $url = 'https://services.tiktokshop.com/open/authorize';

        $queries = [
            'app_key' => $this->appKey,
            'state' => $state,
        ];

        return $url . '?' . http_build_query($queries);
    }

    public function getAccessToken(string $authCode, string $redirectUri)
    {
        $path = '/api/v2/token/get';

        $queries = [
            'app_key' => $this->appKey,
            'app_secret' => $this->appSecret,
            'auth_code' => $authCode,
            'grant_type' => 'authorized_code',
        ];

        $authBaseUrl = 'https://auth.tiktok-shops.com';
        $url = $authBaseUrl . $path . '?' . http_build_query($queries);

        $response = Http::get($url);

        return $response->json();
    }

    public function getAuthorizedShops(string $accessToken)
    {
        $path = '/authorization/202309/shops';

        return $this->request('GET', $path, [], [], $accessToken);
    }

    public function refreshAccessToken(string $refreshToken): array
    {
        $path = '/api/v2/token/refresh';

        $queries = [
            'app_key' => $this->appKey,
            'app_secret' => $this->appSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ];

        $authBaseUrl = 'https://auth.tiktok-shops.com';
        $url = $authBaseUrl . $path . '?' . http_build_query($queries);

        $response = Http::get($url);

        return $response->json();
    }
}