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
        $this->appKey = config('services.tiktok.app_key', env('TIKTOK_APP_KEY'));
        $this->appSecret = config('services.tiktok.app_secret', env('TIKTOK_APP_SECRET'));
        // Default to production Open API
        $this->baseUrl = config('services.tiktok.base_url', 'https://open-api.tiktokglobalshop.com');
    }

    public function generateSignature(string $path, array $queries, $body = null, bool $isMultipart = false, string $method = 'POST'): string
    {
        // 1. Exclude sign and access_token
        $signParams = collect($queries)->except(['sign', 'access_token'])->toArray();
        
        // 2. Sort by key alphabetically
        ksort($signParams);
        
        // 3. Concatenate key value pairs
        $paramString = '';
        foreach ($signParams as $k => $v) {
            $paramString .= $k . $v;
        }
        
        // 4. Encode body (skip if multipart)
        $bodyString = '';
        if (!$isMultipart) {
            if (empty($body) && strtoupper($method) !== 'GET') {
                $bodyString = '{}'; // Empty JSON object for POST/PUT without body
            } elseif (!empty($body)) {
                $bodyString = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }
        
        // 5. Wrap with app secret
        $stringToSign = $this->appSecret . $path . $paramString . $bodyString . $this->appSecret;
        
        // 6. Generate HMAC-SHA256
        return hash_hmac('sha256', $stringToSign, $this->appSecret);
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
            Log::error('TikTok API Error', ['url' => $fullUrl, 'body' => $body, 'response' => $data]);
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
        // Auth endpoints usually use a different path structure and require app_secret directly or in sign
        $path = '/api/v2/token/get';
        $queries = [
            'app_key' => $this->appKey,
            'app_secret' => $this->appSecret,
            'auth_code' => $authCode,
            'grant_type' => 'authorized_code'
        ];

        // This endpoint might not require signing in the standard way, but we will use Http::get
        $url = $this->baseUrl . $path . '?' . http_build_query($queries);
        $response = Http::get($url);
        
        return $response->json();
    }

    public function getAuthorizedShops(string $accessToken)
    {
        // API for fetching shops authorized by the access_token
        $path = '/authorization/202309/shops';
        return $this->request('GET', $path, [], [], $accessToken);
    }
}
