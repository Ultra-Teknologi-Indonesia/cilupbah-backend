<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Modules\Channel\Http\Resources\ChannelShopResource;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Services\LazadaAuthService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Lazada', description: 'Integrasi OAuth Lazada')]
class LazadaStoreController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected LazadaAuthService $authService,
        protected ChannelShopRepository $shopRepository,
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
        try {
            return $this->successPaginatedResponse(
                ChannelShopResource::collection($this->shopRepository->getPaginatedShops()),
                'Daftar toko Lazada berhasil diambil'
            );
        } catch (\Exception $e) {
            throw $e;
        }
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
            return $this->successResponse(
                new ChannelShopResource($this->authService->getStoreModel($id)),
                'Detail toko berhasil diambil'
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal memuat detail.',
                404,
                ['detail' => $e->getMessage()],
                'Terjadi kesalahan',
            );
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
            return $this->errorResponse(
                'Gagal menghapus.',
                404,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
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

            return $this->errorResponse(
                'Gagal memproses aksi.',
                $code,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }
}
