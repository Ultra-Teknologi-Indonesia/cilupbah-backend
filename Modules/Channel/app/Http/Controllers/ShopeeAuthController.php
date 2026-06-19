<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Services\ShopeeAuthService;
use Modules\Channel\Services\ShopeeClient;
use Modules\Channel\Support\OAuthFlow;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Shopee', description: 'Integrasi OAuth Shopee')]
class ShopeeAuthController extends Controller
{
    use ApiResponse;

    private const CHANNEL = 'shopee';

    #[OA\Get(
        path: '/api/v1/shopee/auth',
        summary: 'Buat URL otorisasi Shopee',
        description: 'Mengembalikan URL yang dibuka seller untuk memberi izin akses APP.',
        tags: ['Shopee'],
        responses: [new OA\Response(response: 200, description: 'Auth URL dibuat')]
    )]
    public function redirect(ShopeeClient $client)
    {
        $redirectUri = (string) config('services.shopee.redirect_uri');
        $state = OAuthFlow::issueState(self::CHANNEL);
        $url = $client->getAuthUrl($redirectUri, $state);

        return $this->successResponse(['auth_url' => $url], 'URL otorisasi Shopee berhasil dibuat.');
    }

    #[OA\Get(
        path: '/api/v1/shopee/callback',
        summary: 'Callback OAuth Shopee (menerima authorization code + shop_id)',
        description: 'URL ini didaftarkan sebagai Redirect URL di Shopee Open Platform.',
        tags: ['Shopee'],
        parameters: [
            new OA\Parameter(name: 'code', in: 'query', required: true, description: 'Authorization code dari Shopee', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'shop_id', in: 'query', required: true, description: 'Shop ID dari Shopee', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Toko Shopee berhasil dihubungkan'),
            new OA\Response(response: 302, description: 'Redirect ke frontend dengan status'),
        ]
    )]
    public function callback(Request $request, ShopeeAuthService $authService)
    {
        $code = $request->query('code');
        $shopId = (string) $request->query('shop_id', '');
        $state = $request->query('state');

        if (! $code && ! $state) {
            return $this->successResponse(['service' => 'ready'], 'Shopee callback service aktif.');
        }

        if (! OAuthFlow::consumeState($state, self::CHANNEL)) {
            return $this->finish('invalid_state', 'Sesi otorisasi tidak valid atau kedaluwarsa.', 422);
        }

        if (! $code) {
            return $this->finish('no_code', 'Shopee tidak mengirimkan kode otorisasi.', 400);
        }

        if ($shopId === '') {
            return $this->finish('no_shop_id', 'Shopee tidak mengirimkan shop_id.', 400);
        }

        try {
            $shop = $authService->handleCallback($code, $shopId);

            return $this->finish(null, "Toko Shopee berhasil dihubungkan: {$shop['shop_name']}", 200, [
                'new_shops' => [$shop],
                'count' => 1,
            ]);
        } catch (\Throwable $e) {
            Log::error('Shopee callback gagal', ['message' => $e->getMessage()]);

            return $this->finish('binding_failed', 'Binding gagal: ' . $e->getMessage(), 422);
        }
    }

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
