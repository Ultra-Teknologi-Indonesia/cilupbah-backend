<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Http;

/**
 * Klien OAuth Lazada Open Platform.
 * Token create/refresh memakai signature HMAC-SHA256 (sha256) standar Lazada.
 *
 * Ref: https://open.lazada.com/apps/doc/doc?nodeId=10450&docId=108069
 */
class LazadaClient
{
    protected string $appKey;
    protected string $appSecret;
    protected string $authUrl;

    public function __construct()
    {
        $this->appKey = (string) config('services.lazada.app_key');
        $this->appSecret = (string) config('services.lazada.app_secret');
        $this->authUrl = rtrim((string) config('services.lazada.auth_url', 'https://auth.lazada.com'), '/');

        if (! $this->appKey || ! $this->appSecret) {
            throw new \RuntimeException('Kredensial Lazada belum dikonfigurasi. Set LAZADA_APP_KEY dan LAZADA_APP_SECRET.');
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
