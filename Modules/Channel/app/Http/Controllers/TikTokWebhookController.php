<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Traits\ApiResponse;

class TikTokWebhookController extends Controller
{
    use ApiResponse;
    public function handle(Request $request)
    {
        $rawBody = $request->getContent();

        Log::info('TikTok Webhook Received', [
            'type' => $request->input('type'),
            'shop_id' => $request->input('shop_id'),
        ]);

        $appKey = config('services.tiktok.app_key');
        $appSecret = config('services.tiktok.app_secret');
        $signature = $request->header('authorization') ?? $request->header('x-tts-webhook-signature');

        $calculatedSignature = \Modules\Channel\Helpers\TikTokSignature::generateWebhookSignature($appKey, $rawBody, $appSecret);

        if (! hash_equals($calculatedSignature, (string) $signature)) {
            Log::warning('TikTok Webhook Signature Mismatch', [
                'shop_id' => $request->input('shop_id'),
            ]);
            return $this->errorResponse('Unauthorized', 401);
        }

        $payload = $request->all();

        \Modules\Channel\Jobs\ProcessTikTokWebhook::dispatch($payload)
            ->onQueue(config('queue.names.tiktok_webhooks'));

        return $this->successResponse(['code' => 0], 'Success');
    }
}
