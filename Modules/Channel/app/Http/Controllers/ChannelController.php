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
    public function index(Request $request)
    {
        try {
            $channels = $this->channelService->getPaginatedChannels();
            
            if ($request->is('api/*') || $request->wantsJson()) {
                return $this->successResponse($channels, 'Daftar channel berhasil diambil.');
            }
            
            return view('channel::index', compact('channels'));
        } catch (\Exception $e) {
            if ($request->is('api/*') || $request->wantsJson()) {
                return $this->errorResponse($e->getMessage(), 500);
            }
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Disconnect (soft-delete) a shop from its channel.
     */
    public function disconnectShop(Request $request, int $id)
    {
        try {
            $this->authService->disconnectStore($id);
            if ($request->is('api/*') || $request->wantsJson()) {
                return $this->successResponse(null, 'Toko berhasil diputuskan dari channel.');
            }
            return back()->with('success', 'Toko berhasil diputuskan dari channel.');
        } catch (\Exception $e) {
            if ($request->is('api/*') || $request->wantsJson()) {
                return $this->errorResponse('Gagal memutuskan toko: ' . $e->getMessage(), 500);
            }
            return back()->with('error', 'Gagal memutuskan toko: ' . $e->getMessage());
        }
    }

    /**
     * Refresh the access token for a shop.
     */
    public function refreshShopToken(Request $request, int $id)
    {
        try {
            $result = $this->authService->refreshStoreToken($id);
            if ($request->is('api/*') || $request->wantsJson()) {
                return $this->successResponse($result, "Token toko berhasil diperbarui.");
            }
            return back()->with('success', 'Token toko berhasil diperbarui.');
        } catch (\Exception $e) {
            if ($request->is('api/*') || $request->wantsJson()) {
                return $this->errorResponse('Gagal memperbarui token: ' . $e->getMessage(), 500);
            }
            return back()->with('error', 'Gagal memperbarui token: ' . $e->getMessage());
        }
    }
}
