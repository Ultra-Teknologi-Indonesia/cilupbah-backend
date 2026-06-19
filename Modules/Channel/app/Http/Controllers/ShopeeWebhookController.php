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

    public function debug(Request $request)
    {
        return $this->successResponse([
            'push_url' => config('services.shopee.push_url'),
            'redirect_uri' => config('services.shopee.redirect_uri'),
            'has_partner_key' => config('services.shopee.partner_key') !== null && config('services.shopee.partner_key') !== '',
            'partner_id' => config('services.shopee.partner_id'),
            'request_url' => $request->url(),
            'resolved_push_url' => $this->resolvePushUrl($request),
        ], 'Shopee push config debug.');
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

        if (! $this->isValidSignature($request, $rawBody)) {
            Log::warning('Shopee push signature tidak valid', [
                'ip' => $request->ip(),
                'url' => $request->url(),
                'push_url' => $this->resolvePushUrl($request),
            ]);

            return response('', 401);
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            Log::warning('Shopee push payload bukan JSON valid — diabaikan.');

            return response('', 200);
        }

        if (! $this->isFirstDelivery($payload)) {
            return response('', 200);
        }

        ProcessShopeeWebhook::dispatch($payload);

        return response('', 200);
    }

    protected function isValidSignature(Request $request, string $rawBody): bool
    {
        $partnerKey = (string) config('services.shopee.partner_key');
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
                'expected' => substr($expected, 0, 16) . '…',
                'provided' => substr(strtolower($provided), 0, 16) . '…',
            ]);

            return false;
        }

        return true;
    }

    /**
     * Behind a reverse proxy $request->url() may return the internal URL.
     * Fallback chain: SHOPEE_PUSH_URL → SHOPEE_REDIRECT_URI → $request->url()
     */
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
}
