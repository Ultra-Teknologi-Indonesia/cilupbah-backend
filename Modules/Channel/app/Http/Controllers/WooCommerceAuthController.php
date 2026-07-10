<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Services\WooCommerceAuthService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'WooCommerce', description: 'Integrasi WooCommerce')]
class WooCommerceAuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected WooCommerceAuthService $authService,
    ) {}

    #[OA\Get(
        path: '/api/v1/woocommerce/auth',
        summary: 'Buat URL otorisasi WooCommerce',
        security: [['bearerAuth' => []]],
        tags: ['WooCommerce'],
        responses: [new OA\Response(response: 200, description: 'Auth URL dibuat')]
    )]
    public function redirect(Request $request)
    {
        $validated = $request->validate([
            'store_url' => ['required', 'string', 'max:255'],
        ]);

        $url = $this->authService->buildAuthorizeUrl($validated['store_url']);

        return $this->successResponse(['auth_url' => $url], 'URL otorisasi WooCommerce berhasil dibuat.');
    }

    #[OA\Post(
        path: '/api/v1/woocommerce/connect',
        summary: 'Hubungkan toko WooCommerce dengan kredensial manual',
        security: [['bearerAuth' => []]],
        tags: ['WooCommerce'],
        responses: [
            new OA\Response(response: 200, description: 'Toko terhubung'),
            new OA\Response(response: 422, description: 'Kredensial tidak valid'),
        ]
    )]
    public function connect(Request $request)
    {
        $validated = $request->validate([
            'store_url' => ['required', 'string', 'max:255'],
            'consumer_key' => ['required', 'string'],
            'consumer_secret' => ['required', 'string'],
            'shop_name' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $shop = $this->authService->handleConnect($validated);

            return $this->successResponse([
                'new_shops' => [$shop],
                'count' => 1,
            ], "Toko WooCommerce berhasil dihubungkan: {$shop['shop_name']}");
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    #[OA\Post(
        path: '/api/v1/woocommerce/callback',
        summary: 'Callback WooCommerce auto-authorize (menerima consumer key/secret)',
        tags: ['WooCommerce'],
        responses: [new OA\Response(response: 200, description: 'Kredensial diterima')]
    )]
    public function callback(Request $request)
    {
        $payload = $request->all();

        if (empty($payload['consumer_key']) && empty($payload['user_id'])) {
            return $this->successResponse(['service' => 'ready'], 'WooCommerce callback service aktif.');
        }

        try {
            $shop = $this->authService->handleCallback($payload);

            return $this->successResponse($shop, "Toko WooCommerce berhasil dihubungkan: {$shop['shop_name']}");
        } catch (\Throwable $e) {
            Log::error('WooCommerce callback gagal', ['message' => $e->getMessage()]);

            return $this->errorResponse('Binding gagal: ' . $e->getMessage(), 422);
        }
    }
}
