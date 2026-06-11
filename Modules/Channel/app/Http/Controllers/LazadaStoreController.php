<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Modules\Channel\Services\LazadaAuthService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Lazada', description: 'Integrasi OAuth Lazada')]
class LazadaStoreController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected LazadaAuthService $authService,
    ) {}

    #[OA\Get(
        path: '/api/v1/lazada/stores',
        summary: 'Daftar toko Lazada terhubung',
        security: [['bearerAuth' => []]],
        tags: ['Lazada'],
        responses: [new OA\Response(response: 200, description: 'OK')]
    )]
    public function index()
    {
        return $this->successResponse($this->authService->getStores(), 'Daftar toko Lazada berhasil diambil');
    }

    #[OA\Get(
        path: '/api/v1/lazada/stores/{id}',
        summary: 'Detail toko Lazada (termasuk status token)',
        security: [['bearerAuth' => []]],
        tags: ['Lazada'],
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

    #[OA\Delete(
        path: '/api/v1/lazada/stores/{id}',
        summary: 'Putuskan koneksi toko Lazada',
        security: [['bearerAuth' => []]],
        tags: ['Lazada'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Terputus'),
            new OA\Response(response: 404, description: 'Tidak ditemukan'),
        ]
    )]
    public function destroy(string $id)
    {
        try {
            $this->authService->disconnectStore($id);

            return $this->successResponse(null, 'Toko berhasil diputuskan');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 404);
        }
    }

    #[OA\Post(
        path: '/api/v1/lazada/stores/{id}/refresh-token',
        summary: 'Perbarui access token toko Lazada',
        security: [['bearerAuth' => []]],
        tags: ['Lazada'],
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
