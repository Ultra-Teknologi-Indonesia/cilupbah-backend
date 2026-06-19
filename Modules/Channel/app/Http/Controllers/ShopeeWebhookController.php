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
     * SHOPEE_PUSH_URL lets you pin the public-facing URL Shopee signs with.
     */
    protected function resolvePushUrl(Request $request): string
    {
        $configuredUrl = (string) config('services.shopee.push_url');

        if ($configuredUrl !== '') {
            return $configuredUrl;
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
