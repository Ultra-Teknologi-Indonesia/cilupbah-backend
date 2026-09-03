<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Traits\ApiResponse;
use Modules\Channel\Services\ChannelWebhookService;

class TikTokWebhookController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected ChannelWebhookService $webhookService,
    ) {}

    public function handle(Request $request)
    {
        $rawBody = $request->getContent();

        Log::info('TikTok Webhook Received', [
            'type' => $request->input('type'),
            'shop_id' => $request->input('shop_id'),
        ]);

        $appKey = (string) config('services.tiktok.app_key');
        $appSecret = (string) config('services.tiktok.app_secret');
        $signature = $request->header('authorization') ?? $request->header('x-tts-webhook-signature');

        if ($appKey === '' || $appSecret === '') {
            Log::warning('TikTok Webhook app_key/app_secret kosong — ditolak.', [
                'shop_id' => $request->input('shop_id'),
            ]);
            return $this->errorResponse('Tidak diizinkan', 401);
        }

        $calculatedSignature = \Modules\Channel\Helpers\TikTokSignature::generateWebhookSignature($appKey, $rawBody, $appSecret);

        if (! hash_equals($calculatedSignature, (string) $signature)) {
            Log::warning('TikTok Webhook Signature Mismatch', [
                'shop_id' => $request->input('shop_id'),
            ]);
            return $this->errorResponse('Tidak diizinkan', 401);
        }

        $timestampHeader = $request->header('x-tts-webhook-timestamp')
            ?? $request->header('x-tts-timestamp');

        if ($timestampHeader !== null && $timestampHeader !== '') {
            $ts = (int) $timestampHeader;

            if ($ts > 0 && abs(now()->getTimestamp() - $ts) > 300) {
                Log::warning('TikTok Webhook timestamp kadaluarsa (kemungkinan replay)', [
                    'shop_id' => $request->input('shop_id'),
                    'timestamp' => $ts,
                ]);
                return $this->errorResponse('Timestamp kadaluarsa', 401);
            }
        }

        $payload = $request->all();

        $recorded = $this->webhookService->recordFirstDelivery(
            'tiktok',
            isset($payload['shop_id']) ? (string) $payload['shop_id'] : null,
            \Modules\Channel\Jobs\ProcessTikTokWebhook::idempotencyKey($payload),
            isset($payload['type']) ? (string) $payload['type'] : null,
            $payload,
        );

        if ($recorded === null) {
            return $this->successResponse(['code' => 0, 'duplicate' => true], 'Duplicate webhook delivery ignored');
        }

        $this->webhookService->dispatchTikTok($payload);

        return $this->successResponse(['code' => 0], 'Success');
    }
}
