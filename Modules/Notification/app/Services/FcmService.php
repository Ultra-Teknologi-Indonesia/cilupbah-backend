<?php

namespace Modules\Notification\Services;

use Modules\Notification\Repositories\NotificationRepository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FcmService
{
    private string $projectId;
    private string $credentialsPath;

    public function __construct(
        private NotificationRepository $repository
    ) {
        $this->projectId = config('services.fcm.project_id') ?? '';
        $this->credentialsPath = config('services.fcm.credentials') ?? '';
    }

    public function sendToUser(string $userId, string $title, string $body, array $data = []): int
    {
        $tokens = $this->repository->tokensForUser($userId);
        $sent = 0;

        foreach ($tokens as $token) {
            if ($this->send($token, $title, $body, $data)) {
                $sent++;
            }
        }

        return $sent;
    }

    public function send(string $fcmToken, string $title, string $body, array $data = []): bool
    {
        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            Log::error('FCM: Failed to get access token');
            return false;
        }

        $payload = [
            'message' => [
                'token' => $fcmToken,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
            ],
        ];

        if (!empty($data)) {
            $payload['message']['data'] = array_map('strval', $data);
        }

        $response = Http::withToken($accessToken)
            ->post("https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send", $payload);

        if ($response->successful()) {
            return true;
        }

        if ($response->status() === 404 || $response->status() === 410) {
            $this->repository->deleteToken($fcmToken);
            Log::info('FCM: Removed stale token', ['token' => substr($fcmToken, 0, 20) . '...']);
        } else {
            Log::warning('FCM: Send failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }

        return false;
    }

    private function getAccessToken(): ?string
    {
        return Cache::remember('fcm_access_token', 3500, function () {
            if (!$this->credentialsPath || !file_exists($this->credentialsPath)) {
                Log::error('FCM: Service account credentials not found', ['path' => $this->credentialsPath]);
                return null;
            }

            $credentials = json_decode(file_get_contents($this->credentialsPath), true);

            $now = time();
            $header = base64url_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claim = base64url_encode(json_encode([
                'iss' => $credentials['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]));

            $signature = '';
            openssl_sign("$header.$claim", $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256);
            $jwt = "$header.$claim." . base64url_encode($signature);

            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if ($response->successful()) {
                return $response->json('access_token');
            }

            Log::error('FCM: OAuth token request failed', ['body' => $response->body()]);
            return null;
        });
    }
}

if (!function_exists('base64url_encode')) {
    function base64url_encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
