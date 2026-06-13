<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Channel\Services\TikTokClient;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\Log;

class TikTokAuthController extends Controller
{
    use ApiResponse;

    public function redirect(TikTokClient $client)
    {
        $redirectUri = config('services.tiktok.redirect_uri');
        $url = $client->getAuthUrl($redirectUri);
        return $this->successResponse(['auth_url' => $url], 'Auth URL generated successfully.');
    }

    public function callback(Request $request, \Modules\Channel\Services\TikTokAuthService $authService)
    {
        $code = $request->query('code');
        if (!$code) {
            return $this->errorResponse('Binding gagal: TikTok tidak mengirimkan kode otorisasi.', 400);
        }

        try {
            $redirectUri = config('services.tiktok.redirect_uri');
            $savedShops = $authService->handleCallback($code, $redirectUri);

            $shopNames = collect($savedShops)->pluck('shop_name')->join(', ');
            $count = count($savedShops);

            return $this->successResponse([
                'new_shops' => $savedShops
            ], "{$count} toko TikTok berhasil dihubungkan: {$shopNames}");
        } catch (\Exception $e) {

            try {
                Log::error('TikTok callback processing failed', [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            } catch (\Throwable $_) {

            }

            return $this->errorResponse('Binding gagal: ' . $e->getMessage(), 500);
        }
    }
}
