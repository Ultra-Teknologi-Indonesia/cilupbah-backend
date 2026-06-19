<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Modules\Channel\Services\ShopeeAuthService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Shopee', description: 'Integrasi OAuth Shopee')]
class ShopeeStoreController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected ShopeeAuthService $authService,
    ) {}

    #[OA\Get(
        path: '/api/v1/shopee/stores/{id}',
        summary: 'Detail toko Shopee (termasuk status token)',
        security: [['bearerAuth' => []]],
        tags: ['Shopee'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'OK'),
            new OA\Response(response: 404, description: 'Tidak ditemukan'),
        ]
    )]
    public function show(string $id)
    {
        try {
            return $this->successResponse($this->authService->getStoreDetail($id), 'Detail toko berhasil diambil');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 404);
        }
    }

    #[OA\Post(
        path: '/api/v1/shopee/stores/{id}/refresh-token',
        summary: 'Perbarui access token toko Shopee',
        security: [['bearerAuth' => []]],
        tags: ['Shopee'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Token diperbarui'),
            new OA\Response(response: 404, description: 'Tidak ditemukan'),
            new OA\Response(response: 422, description: 'Refresh gagal'),
        ]
    )]
    public function refreshToken(string $id)
    {
        try {
            return $this->successResponse($this->authService->refreshStoreToken($id), 'Token berhasil diperbarui');
        } catch (\Exception $e) {
            $code = str_contains($e->getMessage(), 'tidak ditemukan') ? 404 : 422;

            return $this->errorResponse($e->getMessage(), $code);
        }
    }
}
