<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\Channel\Http\Resources\ChannelShopResource;
use Modules\Channel\Services\TikTokAuthService;
use Modules\Channel\Repositories\ChannelShopRepository;

class TikTokStoreController extends Controller
{
    use ApiResponse;

    protected TikTokAuthService $authService;
    protected ChannelShopRepository $shopRepository;

    public function __construct(TikTokAuthService $authService, ChannelShopRepository $shopRepository)
    {
        $this->authService = $authService;
        $this->shopRepository = $shopRepository;
    }

    public function index()
    {
        try {
            return $this->successPaginatedResponse(
                ChannelShopResource::collection($this->shopRepository->getPaginatedShops()),
                'Daftar toko berhasil diambil'
            );
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function show(string $id)
    {
        try {
            $store = $this->authService->getStoreDetail($id);
            return $this->successResponse($store, 'Detail toko berhasil diambil');
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal memuat detail.',
                404,
                ['detail' => $e->getMessage()],
                'Terjadi kesalahan',
            );
        }
    }

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

    public function refreshToken(string $id)
    {
        try {
            $result = $this->authService->refreshStoreToken($id);
            return $this->successResponse($result, 'Token berhasil diperbarui');
        } catch (\Exception $e) {
            throw $e;
        }
    }
}
