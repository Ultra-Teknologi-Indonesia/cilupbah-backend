<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Channel\Services\TikTokProductService;
use Modules\Channel\Repositories\ChannelShopRepository;
use App\Traits\ApiResponse;

class TikTokSyncApiController extends Controller
{
    use ApiResponse;

    protected ChannelShopRepository $shopRepository;

    public function __construct(ChannelShopRepository $shopRepository)
    {
        $this->shopRepository = $shopRepository;
    }

    public function pullOrdersAll(TikTokOrderService $orderService)
    {
        try {
            $shops = $this->shopRepository->getShopsByChannelCode('tiktok')
                ->filter(fn ($shop) => $shop->is_active && $shop->access_token);
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

    public function pullProductsAll(TikTokProductService $productService)
    {
        try {
            $shops = $this->shopRepository->getShopsByChannelCode('tiktok')
                ->filter(fn ($shop) => $shop->is_active && $shop->access_token);
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

    public function pullOrders(Request $request, TikTokOrderService $orderService)
    {
        $request->validate(['shop_id' => 'required|string']);
        try {
            $count = $orderService->pullOrders($request->shop_id);
            return $this->successResponse(['count' => $count], "Berhasil menarik {$count} pesanan!");
        } catch (\Exception $e) {
            return $this->errorResponse("Gagal menarik pesanan: " . $e->getMessage(), 500);
        }
    }

    public function declineOrder(Request $request, TikTokOrderService $orderService)
    {
        $request->validate([
            'shop_id' => 'required|string',
            'order_id' => 'required|string',
            'reason' => 'required|string'
        ]);

        try {
            $orderService->declineOrder($request->shop_id, $request->order_id, $request->reason);
            return $this->successResponse(null, "Pesanan {$request->order_id} berhasil ditolak!");
        } catch (\Exception $e) {
            return $this->errorResponse("Gagal menolak pesanan: " . $e->getMessage(), 500);
        }
    }

    public function acceptOrder(Request $request, TikTokOrderService $orderService)
    {
        $request->validate([
            'shop_id' => 'required|string',
            'order_id' => 'required|string'
        ]);

        try {
            $orderService->acceptOrder($request->shop_id, $request->order_id);
            return $this->successResponse(null, "Pesanan {$request->order_id} berhasil diterima (Processing)!");
        } catch (\Exception $e) {
            return $this->errorResponse("Gagal menerima pesanan: " . $e->getMessage(), 500);
        }
    }

    public function pushProduct(Request $request, TikTokProductService $productService)
    {
        $request->validate([
            'shop_id' => 'required|string',
            'product_id' => 'required|string'
        ]);

        try {
            $productService->pushProduct($request->product_id, $request->shop_id);
            return $this->successResponse(null, "Produk (ID: {$request->product_id}) berhasil di-push ke TikTok!");
        } catch (\Exception $e) {
            return $this->errorResponse("Gagal push produk: " . $e->getMessage(), 500);
        }
    }

    public function syncProduct(Request $request, TikTokProductService $productService)
    {
        $request->validate([
            'shop_id' => 'required|string',
            'product_id' => 'required|string'
        ]);

        try {
            $productService->syncPriceAndInventory($request->product_id, $request->shop_id);
            return $this->successResponse(null, "Stok & Harga Produk (ID: {$request->product_id}) berhasil di-sync ke TikTok!");
        } catch (\Exception $e) {
            return $this->errorResponse("Gagal sync produk: " . $e->getMessage(), 500);
        }
    }

    public function bulkPush(Request $request, TikTokProductService $productService)
    {
        $request->validate(['shop_id' => 'required|string']);
        try {
            $failCount = $productService->bulkPushProducts($request->shop_id);
            $message = $failCount > 0
                ? "Bulk push selesai dengan {$failCount} kegagalan."
                : "Semua produk berhasil di-push secara massal.";

            return $this->successResponse(['fail_count' => $failCount], $message);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
    
    public function getCancelReasons(Request $request, TikTokOrderService $orderService)
    {
        try {
            $reasons = $orderService->getCancelReasons();
            return $this->successResponse($reasons, 'Cancel reasons fetched successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function cancelProduct(Request $request, TikTokOrderService $orderService)
    {
        $request->validate([
            'order_id' => 'required|string',
            'reason' => 'required|string'
        ]);

        try {
            $res = $orderService->cancelProduct($request->order_id, $request->reason);
            return $this->successResponse($res, 'Pesanan berhasil dibatalkan');
        } catch (\Exception $e) {
            $status = strpos($e->getMessage(), 'Hanya berlaku') !== false || strpos($e->getMessage(), 'tidak ditemukan') !== false ? 400 : 500;
            return $this->errorResponse($e->getMessage(), $status);
        }
    }
}
