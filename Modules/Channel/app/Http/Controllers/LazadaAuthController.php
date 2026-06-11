<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Services\LazadaAuthService;
use Modules\Channel\Services\LazadaClient;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Lazada', description: 'Integrasi OAuth Lazada')]
class LazadaAuthController extends Controller
{
    use ApiResponse;

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
        $url = $client->getAuthUrl($redirectUri);

        return $this->successResponse(['auth_url' => $url], 'URL otorisasi Lazada berhasil dibuat.');
    }

    /**
     * Callback URL untuk diisikan ke pengaturan APP Lazada.
     * Lazada mengarahkan seller ke sini dengan query ?code=<authorization_code>.
     */
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
            new OA\Response(response: 400, description: 'Authorization code tidak ada'),
        ]
    )]
    public function callback(Request $request, LazadaAuthService $authService)
    {
        $code = $request->query('code');

        if (! $code) {
            return $this->errorResponse('Binding gagal: Lazada tidak mengirimkan kode otorisasi.', 400);
        }

        try {
            $savedShops = $authService->handleCallback($code);

            $shopNames = collect($savedShops)->pluck('shop_name')->join(', ');
            $count = count($savedShops);

            return $this->successResponse(
                ['new_shops' => $savedShops],
                "{$count} toko Lazada berhasil dihubungkan: {$shopNames}"
            );
        } catch (\Throwable $e) {
            Log::error('Lazada callback gagal', ['message' => $e->getMessage()]);

            return $this->errorResponse('Binding gagal: ' . $e->getMessage(), 422);
        }
    }
}
