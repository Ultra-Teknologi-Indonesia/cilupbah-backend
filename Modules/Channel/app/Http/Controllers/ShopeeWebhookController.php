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
            Log::warning('Shopee webhook signature tidak valid', ['ip' => $request->ip()]);

            return $this->errorResponse('Invalid signature', 401);
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            Log::warning('Shopee webhook payload bukan JSON valid — diabaikan.');

            return $this->successResponse(['received' => true], 'OK');
        }

        if (! $this->isFirstDelivery($payload)) {
            return $this->successResponse(['received' => true, 'duplicate' => true], 'OK');
        }

        ProcessShopeeWebhook::dispatch($payload);

        return $this->successResponse(['received' => true], 'OK');
    }

    protected function isValidSignature(Request $request, string $rawBody): bool
    {
        $partnerKey = (string) config('services.shopee.partner_key');
        $provided = (string) $request->header('Authorization', '');

        if ($provided === '' || $partnerKey === '') {
            Log::warning('Shopee webhook signature kosong', [
                'has_auth' => $provided !== '',
                'has_key' => $partnerKey !== '',
            ]);

            return false;
        }

        $pushUrl = $this->resolvePushUrl($request);
        $expected = ShopeeSignature::pushSign($pushUrl, $rawBody, $partnerKey);

        if (! hash_equals($expected, strtolower($provided))) {
            Log::warning('Shopee webhook signature mismatch', [
                'push_url_used' => $pushUrl,
                'request_url' => $request->url(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Resolve the public-facing push URL that Shopee uses for signing.
     * Behind a reverse proxy, $request->url() may return internal URL
     * (e.g. http://127.0.0.1:8000/...) instead of the public URL.
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
