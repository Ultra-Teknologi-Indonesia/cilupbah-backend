<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Channel\Services\TikTokClient;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Support\OAuthFlow;

class TikTokAuthController extends Controller
{
    use ApiResponse;

    private const CHANNEL = 'tiktok';

    public function redirect(TikTokClient $client)
    {
        $redirectUri = config('services.tiktok.redirect_uri');
        $state = OAuthFlow::issueState(self::CHANNEL);
        $url = $client->getAuthUrl($redirectUri, $state);

        return $this->successResponse(['auth_url' => $url], 'Auth URL generated successfully.');
    }

    public function callback(Request $request, \Modules\Channel\Services\TikTokAuthService $authService)
    {
        // Validasi state (CSRF) — sekali pakai.
        if (! OAuthFlow::consumeState($request->query('state'), self::CHANNEL)) {
            return $this->finish('invalid_state', 'Sesi otorisasi tidak valid atau kedaluwarsa.', 422);
        }

        $code = $request->query('code');
        if (! $code) {
            return $this->finish('no_code', 'TikTok tidak mengirimkan kode otorisasi.', 400);
        }

        try {
            $redirectUri = config('services.tiktok.redirect_uri');
            $savedShops = $authService->handleCallback($code, $redirectUri);
            $count = count($savedShops);
            $shopNames = collect($savedShops)->pluck('shop_name')->join(', ');

            return $this->finish(null, "{$count} toko TikTok berhasil dihubungkan: {$shopNames}", 200, [
                'new_shops' => $savedShops,
                'count' => $count,
            ]);
        } catch (\Throwable $e) {
            Log::error('TikTok callback processing failed', ['message' => $e->getMessage()]);

            return $this->finish('binding_failed', 'Binding gagal: ' . $e->getMessage(), 422);
        }
    }

    /**
     * Akhiri callback: redirect ke frontend bila FRONTEND_URL diset, selain itu
     * balas JSON (fallback kompatibel). Tidak pernah melempar 500.
     */
    private function finish(?string $error, string $message, int $code, array $data = [])
    {
        $params = $error ? ['error' => $error] : ['count' => $data['count'] ?? 0];
        $url = OAuthFlow::frontendUrl(self::CHANNEL, $params);

        if ($url) {
            return redirect()->away($url);
        }

        return $error
            ? $this->errorResponse($message, $code)
            : $this->successResponse($data, $message);
    }
}
