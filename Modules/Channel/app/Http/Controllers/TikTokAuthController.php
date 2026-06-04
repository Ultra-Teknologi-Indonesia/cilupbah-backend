<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Channel\Services\TikTokClient;
use App\Traits\ApiResponse;

class TikTokAuthController extends Controller
{
    use ApiResponse;

    public function redirect(TikTokClient $client)
    {
        $redirectUri = env('TIKTOK_REDIRECT_URI');
        $url = $client->getAuthUrl($redirectUri);
        return redirect()->away($url);
    }

    public function callback(Request $request, \Modules\Channel\Services\TikTokAuthService $authService)
    {
        $code = $request->query('code');
        if (!$code) {
            return $this->errorResponse('No auth code provided by TikTok', 400);
        }

        try {
            $redirectUri = env('TIKTOK_REDIRECT_URI');
            $savedShops = $authService->handleCallback($code, $redirectUri);

            return $this->successResponse(
                ['shops_authorized' => $savedShops, 'note' => 'You can now use the tiktok:push-product command with any of the above shop IDs.'],
                'TikTok authorized successfully for ISV'
            );
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
