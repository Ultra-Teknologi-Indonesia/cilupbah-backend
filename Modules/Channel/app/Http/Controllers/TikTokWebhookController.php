<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TikTokWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $rawBody = $request->getContent();
        
        $headers = $request->headers->all();
        
        Log::info('TikTok Webhook Received', [
            'headers' => $headers,
            'body'    => $request->all(),
        ]);

        $appSecret = config('services.tiktok.app_secret');
        $signature = $request->header('authorization') ?? $request->header('x-tts-webhook-signature'); 
        
        $calculatedSignature = \Modules\Channel\Helpers\TikTokSignature::generateFromRequest($request, $appSecret);
        
        if ($signature !== $calculatedSignature) {
            Log::warning('TikTok Webhook Signature Mismatch', [
                'expected' => $calculatedSignature,
                'received' => $signature
            ]);
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $payload = $request->all();

        \Modules\Channel\Jobs\ProcessTikTokWebhook::dispatch($payload)
            ->onConnection('redis')
            ->onQueue('tiktok_webhooks');

        return response()->json([
            'code' => 0,
            'message' => 'Success'
        ], 200);
    }
}
