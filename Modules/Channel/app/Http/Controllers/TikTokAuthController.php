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
            return redirect('/channels')
                ->with('error', 'Binding gagal: TikTok tidak mengirimkan kode otorisasi.');
        }

        try {
            $redirectUri = env('TIKTOK_REDIRECT_URI');
            $savedShops = $authService->handleCallback($code, $redirectUri);

            $shopNames = collect($savedShops)->pluck('shop_name')->join(', ');
            $count = count($savedShops);

            return redirect('/channels')
                ->with('success', "{$count} toko TikTok berhasil dihubungkan: {$shopNames}")
                ->with('new_shops', $savedShops);
        } catch (\Exception $e) {
            return redirect('/channels')
                ->with('error', 'Binding gagal: ' . $e->getMessage());
        }
    }
}
