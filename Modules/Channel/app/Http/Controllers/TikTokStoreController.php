<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
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
            $stores = $this->shopRepository->getPaginatedShops();
            return $this->successResponse($stores, 'Daftar toko berhasil diambil');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function show(int $id)
    {
        try {
            $store = $this->authService->getStoreDetail($id);
            return $this->successResponse($store, 'Detail toko berhasil diambil');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 404);
        }
    }

    public function destroy(int $id)
    {
        try {
            $this->authService->disconnectStore($id);
            return $this->successResponse(null, 'Toko berhasil diputuskan');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 404);
        }
    }

    public function refreshToken(int $id)
    {
        try {
            $result = $this->authService->refreshStoreToken($id);
            return $this->successResponse($result, 'Token berhasil diperbarui');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
