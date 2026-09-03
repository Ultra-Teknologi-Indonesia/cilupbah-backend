<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Channel\Services\TikTokProductService;
use Modules\Channel\Services\ChannelService;
use Modules\Product\Services\CategoryAttributeSyncService;
use Modules\Product\Services\ProductChannelValidationService;
use App\Traits\ApiResponse;

class TikTokSyncApiController extends Controller
{
    use ApiResponse;

    protected ChannelService $channelService;

    public function __construct(
        ChannelService $channelService,
        private readonly CategoryAttributeSyncService $categoryAttributeSyncService,
        private readonly ProductChannelValidationService $validationService,
    )
    {
        $this->channelService = $channelService;
    }

    public function pullOrdersAll(TikTokOrderService $orderService)
    {
        try {
            $summary = $this->channelService->syncOrdersForAllStores(
                'tiktok',
                fn (string $shopId): int => $orderService->pullOrders($shopId),
            );
            $totalCount = $summary['total'];
            $results = collect($summary['stores'])->map(fn (array $store): array => $store['status'] === 'success'
                ? [...$store, 'pulled_count' => $store['pulled_count'] ?? $store['result'] ?? 0]
                : [...$store, 'status' => 'error', 'message' => \Modules\Channel\Support\UploadErrorPresenter::fromMessage('tiktok', $store['error'] ?? '')['reason']]
            )->all();

            return $this->successResponse([
                'total_pulled' => $totalCount,
                'details' => $results
            ], "Berhasil menarik $totalCount pesanan dari seluruh toko TikTok.");
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function pullOrders(Request $request, TikTokOrderService $orderService)
    {
        $request->validate(['shop_id' => 'required|string']);
        try {
            $count = $orderService->pullOrders($request->shop_id);
            return $this->successResponse(['count' => $count], "Berhasil menarik {$count} pesanan!");
        } catch (\Exception $e) {
            throw $e;
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
            throw $e;
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
            throw $e;
        }
    }

    public function shipOrder(Request $request, TikTokOrderService $orderService)
    {
        $request->validate([
            'shop_id'              => 'required|string',
            'order_id'             => 'required|string',
            'tracking_number'      => 'nullable|string',
            'shipping_provider_id' => 'nullable|string',
        ]);

        try {
            $handover = null;
            if ($request->tracking_number || $request->shipping_provider_id) {
                $handover = array_filter([
                    'tracking_number'      => $request->tracking_number,
                    'shipping_provider_id' => $request->shipping_provider_id,
                ]);
            }

            $result = $orderService->readyToShip($request->shop_id, $request->order_id, $handover);

            if (empty($result['shipped'])) {
                return $this->errorResponse('Gagal mengirim order: ' . ($result['message'] ?? 'unknown'), 422, $result);
            }

            return $this->successResponse($result, 'Order TikTok berhasil dikirim.');
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal mengirim order.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    public function airwayBill(Request $request, TikTokOrderService $orderService)
    {
        $request->validate([
            'shop_id'    => 'required|string',
            'package_id' => 'required|string',
            'doc_type'   => 'nullable|string',
        ]);

        try {
            $docType = $request->input('doc_type', 'SHIPPING_LABEL');
            $result = $orderService->getShippingDocument($request->shop_id, $request->package_id, $docType);
            return $this->successResponse($result['data'] ?? $result, 'Air waybill TikTok siap.');
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal ambil AWB.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    public function packages(Request $request, TikTokOrderService $orderService)
    {
        $request->validate([
            'shop_id'    => 'required|string',
            'package_id' => 'required|string',
        ]);

        try {
            $result = $orderService->getPackageDetail($request->shop_id, $request->package_id);
            return $this->successResponse($result['data'] ?? $result, 'Detail package TikTok.');
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal ambil detail package.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    public function handleBuyerCancel(Request $request, TikTokOrderService $orderService)
    {
        $request->validate([
            'shop_id'   => 'required|string',
            'order_id'  => 'required|string',
            'operation' => 'required|string|in:ACCEPT,REJECT',
        ]);

        try {
            $result = $request->operation === 'ACCEPT'
                ? $orderService->acceptBuyerCancellation($request->shop_id, $request->order_id)
                : $orderService->rejectBuyerCancellation($request->shop_id, $request->order_id);

            $msg = $request->operation === 'ACCEPT'
                ? 'Permintaan pembatalan pembeli diterima.'
                : 'Permintaan pembatalan pembeli ditolak.';

            return $this->successResponse($result, $msg);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal menanggapi pembatalan.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    public function cancelOrder(Request $request, TikTokOrderService $orderService)
    {
        $request->validate([
            'order_id'      => 'required|string',
            'cancel_reason' => 'required|string',
        ]);

        try {
            $res = $orderService->cancelProduct($request->order_id, $request->cancel_reason);
            return $this->successResponse($res, 'Order TikTok berhasil dibatalkan.');
        } catch (\Modules\Channel\Exceptions\ChannelCancelException $e) {

            return $this->errorResponse(
                'Gagal membatalkan order.',
                $e->retryable ? 503 : 422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        } catch (\Exception $e) {
            $status = strpos($e->getMessage(), 'Hanya berlaku') !== false || strpos($e->getMessage(), 'tidak ditemukan') !== false ? 422 : 500;
            return $this->errorResponse(
                'Gagal membatalkan order.',
                $status,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    public function cancelReasons(Request $request, TikTokOrderService $orderService)
    {
        try {
            $reasons = $orderService->getCancelReasons();
            return $this->successResponse($reasons, 'Daftar alasan pembatalan TikTok.');
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function pullProductsAll(TikTokProductService $productService)
    {
        try {
            $results = collect($this->channelService->runForAllActiveStores(
                'tiktok',
                fn (string $shopId): mixed => $productService->pullProducts($shopId),
            ))->map(fn (array $store): array => $store['status'] === 'success'
                ? [...$store, 'result' => null]
                : [...$store, 'status' => 'error', 'message' => \Modules\Channel\Support\UploadErrorPresenter::fromMessage('tiktok', $store['error'] ?? '')['reason']]
            )->all();

            return $this->successResponse([
                'details' => $results
            ], "Berhasil menarik produk dari seluruh toko TikTok.");
        } catch (\Exception $e) {
            throw $e;
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
            throw $e;
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
            throw $e;
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
            throw $e;
        }
    }

    public function syncCategories(Request $request, TikTokProductService $productService)
    {
        $validated = $request->validate(['shop_id' => ['required', 'string']]);

        try {
            $count = $productService->syncCategoryTree($validated['shop_id']);
        } catch (\Throwable $e) {
            return $this->errorResponse(
                'Gagal sinkron kategori TikTok.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }

        return $this->successResponse(['synced' => $count], "{$count} kategori TikTok disinkronkan.");
    }

    public function syncCategoryAttributes(Request $request, TikTokProductService $productService)
    {
        $validated = $request->validate([
            'shop_id' => 'required|string',
            'category_id' => 'nullable|string',
        ]);

        try {
            if (! empty($validated['category_id'])) {
                $count = $productService->syncCategoryAttributes($validated['shop_id'], $validated['category_id']);

                $this->categoryAttributeSyncService->materializeAllMapped();
                $this->validationService->queueRecomputeForMappedProducts();

                return $this->successResponse(
                    ['synced' => $count, 'category_id' => $validated['category_id']],
                    "{$count} atribut TikTok disinkronkan."
                );
            }

            $results = $productService->syncAllMappedCategoryAttributes($validated['shop_id']);

            $this->categoryAttributeSyncService->materializeAllMapped();
            $this->validationService->queueRecomputeForMappedProducts();
        } catch (\Throwable $e) {
            return $this->errorResponse(
                'Gagal sinkron atribut TikTok.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }

        return $this->successResponse(
            ['categories' => $results, 'total' => array_sum($results)],
            array_sum($results) . ' atribut dari ' . count($results) . ' kategori disinkronkan.'
        );
    }
}
