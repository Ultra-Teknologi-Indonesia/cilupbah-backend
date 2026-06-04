<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\Channel\Services\TikTokAuthService;

class TikTokStoreController extends Controller
{
    use ApiResponse;

    protected TikTokAuthService $authService;

    public function __construct(TikTokAuthService $authService)
    {
        $this->authService = $authService;
    }

    public function index()
    {
        try {
            $stores = $this->authService->getStores();
            return $this->successResponse($stores, 'Daftar toko TikTok berhasil diambil');
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
