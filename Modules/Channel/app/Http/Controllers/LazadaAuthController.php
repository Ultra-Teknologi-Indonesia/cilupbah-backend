<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Services\LazadaAuthService;
use Modules\Channel\Services\LazadaClient;
use Modules\Channel\Support\OAuthFlow;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Lazada', description: 'Integrasi OAuth Lazada')]
class LazadaAuthController extends Controller
{
    use ApiResponse;

    private const CHANNEL = 'lazada';

    #[OA\Get(
        path: '/api/v1/lazada/auth',
        summary: 'Buat URL otorisasi Lazada',
        description: 'Mengembalikan URL yang dibuka seller untuk memberi izin akses APP.',
        tags: ['Lazada'],
        responses: [new OA\Response(response: 200, description: 'Auth URL dibuat')]
    )]
    public function redirect(LazadaClient $client)
    {
        $redirectUri = (string) config('services.lazada.redirect_uri');
        $state = OAuthFlow::issueState(self::CHANNEL);
        $url = $client->getAuthUrl($redirectUri, $state);

        return $this->successResponse(['auth_url' => $url], 'URL otorisasi Lazada berhasil dibuat.');
    }

    #[OA\Get(
        path: '/api/v1/lazada/callback',
        summary: 'Callback OAuth Lazada (menerima authorization code)',
        description: 'URL ini didaftarkan sebagai Callback URL di Lazada Open Platform.',
        tags: ['Lazada'],
        parameters: [
            new OA\Parameter(name: 'code', in: 'query', required: true, description: 'Authorization code dari Lazada', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Toko Lazada berhasil dihubungkan'),
            new OA\Response(response: 302, description: 'Redirect ke frontend dengan status'),
        ]
    )]
    public function callback(Request $request, LazadaAuthService $authService)
    {
        $code = $request->query('code');
        $state = $request->query('state');

        // Probe ketersediaan dari Lazada (verify callback): tanpa code & state →
        // BALAS 200 dan JANGAN redirect, agar verifikasi "message receiving service" lulus.
        if (! $code && ! $state) {
            return $this->successResponse(['service' => 'ready'], 'Lazada callback service aktif.');
        }

        // Validasi state (CSRF) — sekali pakai (hanya untuk alur OAuth nyata).
        if (! OAuthFlow::consumeState($state, self::CHANNEL)) {
            return $this->finish('invalid_state', 'Sesi otorisasi tidak valid atau kedaluwarsa.', 422);
        }

        if (! $code) {
            return $this->finish('no_code', 'Lazada tidak mengirimkan kode otorisasi.', 400);
        }

        try {
            $savedShops = $authService->handleCallback($code);
            $count = count($savedShops);
            $shopNames = collect($savedShops)->pluck('shop_name')->join(', ');

            return $this->finish(null, "{$count} toko Lazada berhasil dihubungkan: {$shopNames}", 200, [
                'new_shops' => $savedShops,
                'count' => $count,
            ]);
        } catch (\Throwable $e) {
            Log::error('Lazada callback gagal', ['message' => $e->getMessage()]);

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
