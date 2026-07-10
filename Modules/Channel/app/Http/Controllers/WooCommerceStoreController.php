<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Modules\Channel\Http\Resources\ChannelShopResource;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Services\WooCommerceAuthService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'WooCommerce', description: 'Integrasi WooCommerce')]
class WooCommerceStoreController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected WooCommerceAuthService $authService,
        protected ChannelShopRepository $shopRepository,
    ) {}

    #[OA\Get(
        path: '/api/v1/woocommerce/stores',
        summary: 'Daftar toko WooCommerce terhubung',
        security: [['bearerAuth' => []]],
        tags: ['WooCommerce'],
        responses: [new OA\Response(response: 200, description: 'OK')]
    )]
    public function index()
    {
        try {
            return $this->successPaginatedResponse(
                ChannelShopResource::collection($this->shopRepository->getPaginatedShops()),
                'Daftar toko WooCommerce berhasil diambil'
            );
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    #[OA\Get(
        path: '/api/v1/woocommerce/stores/{id}',
        summary: 'Detail toko WooCommerce',
        security: [['bearerAuth' => []]],
        tags: ['WooCommerce'],
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
            return $this->errorResponse($e->getMessage(), 404);
        }
    }

    #[OA\Delete(
        path: '/api/v1/woocommerce/stores/{id}',
        summary: 'Putuskan koneksi toko WooCommerce',
        security: [['bearerAuth' => []]],
        tags: ['WooCommerce'],
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
}
