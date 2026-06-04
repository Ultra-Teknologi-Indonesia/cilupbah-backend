<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Channel\Services\TikTokProductService;
use Modules\Channel\Models\ChannelShop;
use App\Traits\ApiResponse;

class TikTokAutoSyncController extends Controller
{
    use ApiResponse;

    /**
     * Pull orders for all TikTok shops
     */
    public function pullOrders(TikTokOrderService $orderService)
    {
        try {
            // Get all active TikTok shops
            $shops = ChannelShop::whereHas('channel', fn($q) => $q->where('code', 'tiktok'))->where('is_active', true)->get();
            $totalCount = 0;
            $results = [];

            foreach ($shops as $shop) {
                try {
                    $count = $orderService->pullOrders($shop->shop_id);
                    $totalCount += $count;
                    $results[] = [
                        'shop_id' => $shop->shop_id,
                        'shop_name' => $shop->shop_name,
                        'status' => 'success',
                        'pulled_count' => $count
                    ];
                } catch (\Exception $e) {
                    $results[] = [
                        'shop_id' => $shop->shop_id,
                        'shop_name' => $shop->shop_name,
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ];
                }
            }

            return $this->successResponse([
                'total_pulled' => $totalCount,
                'details' => $results
            ], "Berhasil menarik $totalCount pesanan dari seluruh toko TikTok.");
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Pull/Sync products for all TikTok shops
     */
    public function pullProducts(TikTokProductService $productService)
    {
        try {
            $shops = ChannelShop::whereHas('channel', fn($q) => $q->where('code', 'tiktok'))->where('is_active', true)->get();
            $results = [];

            foreach ($shops as $shop) {
                try {
                    $productService->pullProducts($shop->shop_id);
                    $results[] = [
                        'shop_id' => $shop->shop_id,
                        'shop_name' => $shop->shop_name,
                        'status' => 'success'
                    ];
                } catch (\Exception $e) {
                    $results[] = [
                        'shop_id' => $shop->shop_id,
                        'shop_name' => $shop->shop_name,
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ];
                }
            }

            return $this->successResponse([
                'details' => $results
            ], "Berhasil menarik produk dari seluruh toko TikTok.");
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
