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

    /**
     * Penerima push message Lazada (didaftarkan di console Lazada Open Platform).
     * Verifikasi signature → balas cepat 200 → proses asinkron. Tidak pernah 500.
     */
    #[OA\Post(
        path: '/api/v1/lazada/webhook',
        summary: 'Webhook masuk Lazada (order status, QC produk, dsb)',
        tags: ['Lazada'],
        responses: [
            new OA\Response(response: 200, description: 'Diterima'),
            new OA\Response(response: 401, description: 'Signature tidak valid'),
        ]
    )]
    public function handle(Request $request)
    {
        $rawBody = $request->getContent();

        if (! $this->isValidSignature($request, $rawBody)) {
            Log::warning('Lazada webhook signature tidak valid', ['ip' => $request->ip()]);

            return $this->errorResponse('Invalid signature', 401);
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            // Payload aneh: log lalu tetap 200 agar Lazada tidak retry-storm.
            Log::warning('Lazada webhook payload bukan JSON valid — diabaikan.');

            return $this->successResponse(['received' => true], 'OK');
        }

        // Dedup: Lazada bisa mengirim ulang event yang sama.
        if (! $this->isFirstDelivery($payload)) {
            return $this->successResponse(['received' => true, 'duplicate' => true], 'OK');
        }

        ProcessLazadaWebhook::dispatch($payload);

        return $this->successResponse(['received' => true], 'OK');
    }

    /**
     * Signature push Lazada: HMAC-SHA256(app_secret, app_key + rawBody), hex,
     * dikirim di header Authorization. Dibandingkan constant-time.
     */
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

    /** True hanya untuk pengiriman pertama event ini (dedup 24 jam via cache). */
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
