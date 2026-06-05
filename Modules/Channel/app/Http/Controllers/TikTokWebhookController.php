<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TikTokWebhookController extends Controller
{
    public function handle(Request $request)
    {
        // 1. Get raw request body for signature verification
        $rawBody = $request->getContent();
        
        // 2. Extract headers
        $headers = $request->headers->all();
        
        // 3. Log the incoming webhook for debugging
        Log::info('TikTok Webhook Received', [
            'headers' => $headers,
            'body'    => $request->all(),
        ]);

        // 4. (Optional) Verify Signature
        // $appSecret = config('services.tiktok.app_secret');
        // $signature = $request->header('authorization'); // or 'x-tts-webhook-signature'
        // $calculatedSignature = hash_hmac('sha256', $rawBody, $appSecret);
        // if ($signature !== $calculatedSignature) {
        //     Log::warning('TikTok Webhook Signature Mismatch');
        //     return response()->json(['message' => 'Unauthorized'], 401);
        // }

        $payload = $request->all();
        $type = $payload['type'] ?? null;

        // 5. Handle based on webhook type
        switch ($type) {
            case 1: // ORDER_STATUS_CHANGE
            case '1':
                // TODO: Handle order status change
                // e.g., Update local order status, trigger TikTokOrderService pullOrder
                break;
            case 2: // REVERSE_ORDER_STATUS_CHANGE (Return/Refund)
            case '2':
                // TODO: Handle reverse order
                break;
            case 3: // RECEIVER_ADDRESS_UPDATED
            case '3':
                // TODO: Handle address update
                break;
            case 4: // PACKAGE_STATUS_CHANGE
            case '4':
                // TODO: Handle package status change
                break;
            case 5: // PRODUCT_STATUS_CHANGE
            case '5':
                // TODO: Handle product status change
                break;
            default:
                Log::info('Unhandled TikTok Webhook Type: ' . $type);
                break;
        }

        // TikTok expects a 200 OK response with a success structure
        return response()->json([
            'code' => 0,
            'message' => 'Success'
        ], 200);
    }
}
