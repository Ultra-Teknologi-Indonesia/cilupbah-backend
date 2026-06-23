<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Helpers\ShopeeSignature;
use Modules\Channel\Jobs\ProcessShopeeWebhook;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Shopee', description: 'Integrasi OAuth Shopee')]
class ShopeeWebhookController extends Controller
{
    use ApiResponse;

    public function verify()
    {
        return $this->successResponse(['service' => 'ready'], 'Shopee webhook service aktif.');
    }

    public function ping()
    {
        return response('', 200);
    }

    public function debug(Request $request)
    {
        $logFile = storage_path('logs/shopee-push-debug.json');
        $entries = file_exists($logFile)
            ? json_decode(file_get_contents($logFile), true) ?? []
            : [];

        $partnerKey = (string) config('services.shopee.partner_key');
        $pushUrl = $this->resolvePushUrl($request);

        $lastEntry = end($entries) ?: null;
        $replay = null;
        if ($lastEntry && ($lastEntry['has_authorization'] ?? false)) {
            $replayBody = $lastEntry['body_full'] ?? $lastEntry['body_preview'] ?? '';
            $replayAuth = '';
            $rawHeaders = $lastEntry['headers'] ?? [];
            $replayAuth = $rawHeaders['authorization'] ?? '';

            $replay = [
                'shopee_authorization' => $replayAuth,
                'body_used' => $replayBody,
                'url_used' => $pushUrl,
                'base_string' => $pushUrl . '|' . $replayBody,
                'our_signature' => ShopeeSignature::pushSign($pushUrl, $replayBody, $partnerKey),
                'match' => hash_equals(
                    ShopeeSignature::pushSign($pushUrl, $replayBody, $partnerKey),
                    strtolower($replayAuth)
                ),
            ];
        }

        return response()->json([
            'config' => [
                'push_url' => config('services.shopee.push_url'),
                'redirect_uri' => config('services.shopee.redirect_uri'),
                'has_partner_key' => $partnerKey !== '',
                'partner_key_length' => strlen($partnerKey),
                'partner_key_prefix' => substr($partnerKey, 0, 8),
                'partner_key_sha256' => hash('sha256', $partnerKey),
                'partner_id' => config('services.shopee.partner_id'),
                'request_url' => $request->url(),
                'resolved_push_url' => $pushUrl,
            ],
            'signature_replay' => $replay,
            'recent_requests' => array_slice($entries, -20),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/shopee/webhook',
        summary: 'Push (webhook) masuk Shopee — order status, item update, dsb',
        tags: ['Shopee'],
        responses: [
            new OA\Response(response: 200, description: 'Diterima'),
            new OA\Response(response: 401, description: 'Signature tidak valid'),
        ]
    )]
    public function handle(Request $request)
    {
        $rawBody = $request->getContent();
        $result = 'unknown';

        try {
            if ($request->header('Authorization', '') === '') {
                Log::info('Shopee push verification probe', ['ip' => $request->ip()]);
                $result = 'verification_probe_200';

                return response('', 200);
            }

            $payload = json_decode($rawBody, true);

            if (is_array($payload) && ($payload['code'] ?? -1) === 0 && isset($payload['data']['verify_info'])) {
                $signatureOk = $this->isValidSignature($request, $rawBody);
                Log::info('Shopee push verification message', [
                    'ip' => $request->ip(),
                    'signature_valid' => $signatureOk,
                    'verify_info' => $payload['data']['verify_info'],
                ]);
                $result = $signatureOk ? 'verification_signed_200' : 'verification_sig_mismatch_200';

                return response('', 200);
            }

            if (! $this->isValidSignature($request, $rawBody)) {
                if (config('services.shopee.verify_push_signature', true)) {
                    Log::warning('Shopee push signature tidak valid', [
                        'ip' => $request->ip(),
                        'url' => $request->url(),
                        'push_url' => $this->resolvePushUrl($request),
                    ]);
                    $result = 'invalid_signature_401';

                    return response('', 401);
                }

                // Signature tidak cocok tapi verifikasi dimatikan (mis. sandbox dengan
                // push key yang tidak ter-sync). Push hanya dipakai sebagai trigger —
                // data order tetap ditarik ulang dari API terautentikasi, jadi isi push
                // tidak dipercaya. Lanjut proses.
                Log::warning('Shopee push signature mismatch — diproses tanpa verifikasi (verify_push_signature=false)', [
                    'ip' => $request->ip(),
                ]);
                $result = 'signature_bypassed';
            }

            if (! is_array($payload)) {
                Log::warning('Shopee push payload bukan JSON valid — diabaikan.');
                $result = 'invalid_json_200';

                return response('', 200);
            }

            if (! $this->isFirstDelivery($payload)) {
                $result = 'duplicate_200';

                return response('', 200);
            }

            ProcessShopeeWebhook::dispatch($payload);
            $result = 'dispatched_200';

            return response('', 200);
        } finally {
            $this->logPushRequest($request, $rawBody, $result);
        }
    }

    protected function isValidSignature(Request $request, string $rawBody): bool
    {
        $pushPartnerKey = (string) config('services.shopee.push_partner_key');
        $partnerKey = $pushPartnerKey !== '' ? $pushPartnerKey : (string) config('services.shopee.partner_key');
        $provided = (string) $request->header('Authorization', '');

        if ($provided === '' || $partnerKey === '') {
            Log::warning('Shopee push auth/key kosong', [
                'has_auth' => $provided !== '',
                'has_key' => $partnerKey !== '',
            ]);

            return false;
        }

        $pushUrl = $this->resolvePushUrl($request);
        $expected = ShopeeSignature::pushSign($pushUrl, $rawBody, $partnerKey);

        if (! hash_equals($expected, strtolower($provided))) {
            Log::warning('Shopee push signature mismatch', [
                'push_url_used' => $pushUrl,
                'request_url' => $request->url(),
                'expected' => $expected,
                'provided' => strtolower($provided),
                'key_source' => $pushPartnerKey !== '' ? 'push_partner_key' : 'partner_key',
                'body_len' => strlen($rawBody),
            ]);

            return false;
        }

        return true;
    }

    protected function resolvePushUrl(Request $request): string
    {
        $pushUrl = (string) config('services.shopee.push_url');
        if ($pushUrl !== '') {
            return $pushUrl;
        }

        $redirectUri = (string) config('services.shopee.redirect_uri');
        if ($redirectUri !== '') {
            return strtok($redirectUri, '?');
        }

        return $request->url();
    }

    protected function isFirstDelivery(array $payload): bool
    {
        $data = $payload['data'] ?? [];

        $key = 'shopee:webhook:' . md5(json_encode([
            $payload['shop_id'] ?? '',
            $payload['code'] ?? '',
            $payload['timestamp'] ?? '',
            $data['ordersn'] ?? $data['order_sn'] ?? $data['item_id'] ?? '',
            $data['status'] ?? '',
        ]));

        return Cache::add($key, 1, now()->addDay());
    }

    protected function logPushRequest(Request $request, string $rawBody, string $result): void
    {
        try {
            $logFile = storage_path('logs/shopee-push-debug.json');
            $entries = file_exists($logFile)
                ? json_decode(file_get_contents($logFile), true) ?? []
                : [];

            $auth = $request->header('Authorization', '');
            $entries[] = [
                'time' => now()->toIso8601String(),
                'method' => $request->method(),
                'url' => $request->url(),
                'full_url' => $request->fullUrl(),
                'ip' => $request->ip(),
                'has_authorization' => $auth !== '',
                'authorization_preview' => $auth !== '' ? substr($auth, 0, 20) . '...' : '(empty)',
                'content_type' => $request->header('Content-Type'),
                'body_length' => strlen($rawBody),
                'body_full' => $rawBody,
                'body_preview' => substr($rawBody, 0, 200),
                'resolved_push_url' => $this->resolvePushUrl($request),
                'result' => $result,
                'headers' => collect($request->headers->all())
                    ->map(fn ($v) => implode(', ', $v))
                    ->except(['cookie'])
                    ->toArray(),
            ];

            $entries = array_slice($entries, -50);

            file_put_contents($logFile, json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            Log::error('Failed to write shopee push debug log', ['error' => $e->getMessage()]);
        }
    }
}
