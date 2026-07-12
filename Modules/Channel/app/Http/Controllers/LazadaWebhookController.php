<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Jobs\ProcessLazadaWebhook;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Lazada', description: 'Integrasi OAuth Lazada')]
class LazadaWebhookController extends Controller
{
    use ApiResponse;

    #[OA\Post(
        path: '/api/v1/lazada/webhook',
        summary: 'Webhook masuk Lazada (order status, QC produk, dsb)',
        tags: ['Lazada'],
        responses: [
            new OA\Response(response: 200, description: 'Diterima'),
            new OA\Response(response: 401, description: 'Signature tidak valid'),
        ]
    )]

    public function verify()
    {
        return $this->successResponse(['service' => 'ready'], 'Lazada webhook service aktif.');
    }

    public function handle(Request $request)
    {
        $rawBody = $request->getContent();

        if (! $this->isValidSignature($request, $rawBody)) {
            Log::warning('Lazada webhook signature tidak valid', ['ip' => $request->ip()]);

            return $this->errorResponse('Signature tidak valid', 401);
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {

            Log::warning('Lazada webhook payload bukan JSON valid — diabaikan.');

            return $this->successResponse(['received' => true], 'OK');
        }

        if (! $this->isFirstDelivery($payload)) {
            return $this->successResponse(['received' => true, 'duplicate' => true], 'OK');
        }

        ProcessLazadaWebhook::dispatch($payload);

        return $this->successResponse(['received' => true], 'OK');
    }

    protected function isValidSignature(Request $request, string $rawBody): bool
    {
        $appKey = (string) config('services.lazada.app_key');
        $appSecret = (string) config('services.lazada.app_secret');
        $provided = (string) $request->header('Authorization', '');

        if ($provided === '' || $appSecret === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $appKey . $rawBody, $appSecret);

        return hash_equals($expected, strtolower($provided));
    }

    protected function isFirstDelivery(array $payload): bool
    {
        $key = 'lazada:webhook:' . md5(json_encode([
            $payload['seller_id'] ?? '',
            $payload['message_type'] ?? '',
            $payload['timestamp'] ?? '',
            $payload['data']['trade_order_id'] ?? $payload['data']['item_id'] ?? '',
            $payload['data']['order_status'] ?? $payload['data']['status'] ?? '',
        ]));

        return Cache::add($key, 1, now()->addDay());
    }
}
