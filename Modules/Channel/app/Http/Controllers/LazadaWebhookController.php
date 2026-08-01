<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Jobs\ProcessLazadaWebhook;
use Modules\Channel\Repositories\ChannelWebhookInboxRepository;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Lazada', description: 'Integrasi OAuth Lazada')]
class LazadaWebhookController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected ChannelWebhookInboxRepository $inbox,
    ) {}

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
        return $this->inbox->recordFirstDelivery(
            'lazada',
            isset($payload['seller_id']) ? (string) $payload['seller_id'] : null,
            ProcessLazadaWebhook::idempotencyKey($payload),
            isset($payload['message_type']) ? (string) $payload['message_type'] : null,
            $payload,
        ) !== null;
    }
}
