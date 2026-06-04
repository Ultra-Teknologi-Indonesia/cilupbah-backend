<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\Channel\Services\ChannelService;
use Modules\Channel\Services\TikTokAuthService;

class ChannelController extends Controller
{
    use ApiResponse;

    protected ChannelService $channelService;
    protected TikTokAuthService $authService;

    public function __construct(ChannelService $channelService, TikTokAuthService $authService)
    {
        $this->channelService = $channelService;
        $this->authService = $authService;
    }

    /**
     * Display paginated channels with their bound shops.
     */
    public function index()
    {
        try {
            $channels = $this->channelService->getPaginatedChannels();
            return $this->successResponse($channels, 'Daftar channel berhasil diambil.');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Disconnect (soft-delete) a shop from its channel.
     */
    public function disconnectShop(int $id)
    {
        try {
            $this->authService->disconnectStore($id);
            return $this->successResponse(null, 'Toko berhasil diputuskan dari channel.');
        } catch (\Exception $e) {
            return $this->errorResponse('Gagal memutuskan toko: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Refresh the access token for a shop.
     */
    public function refreshShopToken(int $id)
    {
        try {
            $result = $this->authService->refreshStoreToken($id);
            return $this->successResponse($result, "Token toko berhasil diperbarui.");
        } catch (\Exception $e) {
            return $this->errorResponse('Gagal memperbarui token: ' . $e->getMessage(), 500);
        }
    }
}
