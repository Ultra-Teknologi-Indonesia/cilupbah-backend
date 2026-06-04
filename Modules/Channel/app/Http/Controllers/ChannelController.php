<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\Channel\Models\Channel;
use Modules\Channel\Services\TikTokAuthService;

class ChannelController extends Controller
{
    use ApiResponse;

    protected TikTokAuthService $authService;

    public function __construct(TikTokAuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Display channels with their bound shops.
     */
    public function index()
    {
        $channels = Channel::with('shops')->get();
        return view('channel::index', compact('channels'));
    }

    /**
     * Disconnect (soft-delete) a shop from its channel.
     */
    public function disconnectShop(int $id)
    {
        try {
            $this->authService->disconnectStore($id);
            return redirect()->route('channel.index')
                ->with('success', 'Toko berhasil diputuskan dari channel.');
        } catch (\Exception $e) {
            return redirect()->route('channel.index')
                ->with('error', 'Gagal memutuskan toko: ' . $e->getMessage());
        }
    }

    /**
     * Refresh the access token for a shop.
     */
    public function refreshShopToken(int $id)
    {
        try {
            $result = $this->authService->refreshStoreToken($id);
            return redirect()->route('channel.index')
                ->with('success', "Token toko \"{$result['shop_name']}\" berhasil diperbarui.");
        } catch (\Exception $e) {
            return redirect()->route('channel.index')
                ->with('error', 'Gagal memperbarui token: ' . $e->getMessage());
        }
    }
}
