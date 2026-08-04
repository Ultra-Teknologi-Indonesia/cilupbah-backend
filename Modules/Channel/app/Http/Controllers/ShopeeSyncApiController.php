<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Services\ShopeeProductService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Shopee', description: 'Integrasi OAuth Shopee')]
class ShopeeSyncApiController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected ShopeeOrderService $orderService,
        protected ChannelShopRepository $shopRepository,
    ) {}

    #[OA\Post(
        path: '/api/v1/shopee/sync/pull',
        summary: 'Tarik order Shopee untuk satu toko',
        tags: ['Shopee'],
        responses: [new OA\Response(response: 200, description: 'Jumlah order tersinkron')]
    )]
    public function pullOrders(Request $request)
    {
        $validated = $request->validate([
            'shop_id' => ['required', 'string'],
            'updated_after' => ['nullable', 'integer'],
        ]);

        $count = $this->orderService->pullOrders($validated['shop_id'], $validated['updated_after'] ?? null);

        return $this->successResponse(['synced' => $count], "{$count} order Shopee berhasil disinkronkan.");
    }

    #[OA\Post(
        path: '/api/v1/shopee/auto-sync/pull-orders',
        summary: 'Tarik order Shopee untuk semua toko terhubung',
        tags: ['Shopee'],
        responses: [new OA\Response(response: 200, description: 'Ringkasan sinkronisasi per toko')]
    )]
    public function pullOrdersAll(Request $request)
    {
        $updatedAfter = $request->integer('updated_after') ?: null;

        $results = [];
        $total = 0;

        foreach ($this->shopRepository->getShopsByChannelCode('shopee') as $shop) {
            if (! $shop->is_active || ! $shop->access_token) {
                continue;
            }

            try {
                $count = $this->orderService->pullOrders($shop->shop_id, $updatedAfter);
                $this->shopRepository->markOrderSyncOk($shop->id);
                $results[] = ['shop_id' => $shop->shop_id, 'synced' => $count];
                $total += $count;
            } catch (\Throwable $e) {
                $this->shopRepository->markOrderSyncProblem($shop->id, $e->getMessage());
                $results[] = ['shop_id' => $shop->shop_id, 'error' => $e->getMessage()];
            }
        }

        return $this->successResponse(['total' => $total, 'shops' => $results], "{$total} order Shopee disinkronkan dari semua toko.");
    }

    #[OA\Post(path: '/api/v1/shopee/sync/ship', summary: 'Terima & kirim order Shopee', tags: ['Shopee'])]
    public function shipOrder(Request $request)
    {
        $validated = $request->validate([
            'shop_id' => ['required', 'string'],
            'order_sn' => ['required', 'string'],
            'method' => ['nullable', 'string', 'in:pickup,dropoff,non_integrated'],
            'address_id' => ['nullable'],
            'pickup_time_id' => ['nullable'],
            'tracking_number' => ['nullable', 'string'],
            'branch_id' => ['nullable'],
            'sender_real_name' => ['nullable', 'string'],
        ]);

        $opts = array_filter(
            $request->only(['method', 'address_id', 'pickup_time_id', 'tracking_number', 'branch_id', 'sender_real_name']),
            fn ($v) => $v !== null
        );

        try {
            $result = $this->orderService->shipOrder($validated['shop_id'], $validated['order_sn'], $opts);

            if (empty($result['shipped'])) {
                return $this->errorResponse('Gagal mengirim order: ' . ($result['error'] ?? 'unknown'), 422, $result);
            }

            return $this->successResponse($result, 'Order Shopee berhasil dikirim.');
        } catch (\Throwable $e) {
            return $this->errorResponse(
                'Gagal mengirim order.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Post(path: '/api/v1/shopee/sync/mass-ship', summary: 'Kirim massal order Shopee', tags: ['Shopee'])]
    public function massShipOrder(Request $request)
    {
        $validated = $request->validate([
            'shop_id' => ['required', 'string'],
            'order_sns' => ['required', 'array', 'min:1'],
            'order_sns.*' => ['required', 'string'],
            'method' => ['nullable', 'string', 'in:pickup,dropoff,non_integrated'],
            'address_id' => ['nullable'],
            'pickup_time_id' => ['nullable'],
            'branch_id' => ['nullable'],
            'sender_real_name' => ['nullable', 'string'],
        ]);

        $opts = array_filter(
            $request->only(['method', 'address_id', 'pickup_time_id', 'branch_id', 'sender_real_name']),
            fn ($v) => $v !== null
        );

        try {
            $result = $this->orderService->massShipOrder($validated['shop_id'], $validated['order_sns'], $opts);

            return $this->successResponse($result, 'Kirim massal Shopee diproses.');
        } catch (\Throwable $e) {
            return $this->errorResponse(
                'Gagal kirim massal.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Get(path: '/api/v1/shopee/sync/awb', summary: 'Cetak / ambil air waybill Shopee', tags: ['Shopee'])]
    public function airwayBill(Request $request)
    {
        $validated = $request->validate([
            'shop_id' => ['required', 'string'],
            'order_sn' => ['required', 'string'],
            'doc_type' => ['nullable', 'string'],
        ]);

        try {
            $result = $this->orderService->getAirwayBill(
                $validated['shop_id'],
                $validated['order_sn'],
                $validated['doc_type'] ?? 'NORMAL_AIR_WAYBILL'
            );

            if (empty($result['ready'])) {
                return $this->errorResponse('AWB belum siap: ' . ($result['error'] ?? 'unknown'), 422, $result);
            }

            return $this->successResponse($result, 'Air waybill Shopee siap.');
        } catch (\Throwable $e) {
            return $this->errorResponse(
                'Gagal ambil AWB.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Get(path: '/api/v1/shopee/sync/packages', summary: 'Cari daftar paket Shopee', tags: ['Shopee'])]
    public function packages(Request $request)
    {
        $validated = $request->validate([
            'shop_id' => ['required', 'string'],
            'package_status' => ['nullable', 'integer'],
            'cursor' => ['nullable', 'string'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
            'logistics_channel_id' => ['nullable', 'integer'],
        ]);

        $opts = array_filter(
            $request->only(['package_status', 'cursor', 'page_size', 'logistics_channel_id']),
            fn ($v) => $v !== null
        );

        try {
            return $this->successResponse($this->orderService->searchPackageList($validated['shop_id'], $opts), 'Daftar paket Shopee.');
        } catch (\Throwable $e) {
            return $this->errorResponse(
                'Gagal ambil daftar paket.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Post(path: '/api/v1/shopee/sync/update-shipping', summary: 'Retry pengiriman order Shopee', tags: ['Shopee'])]
    public function updateShipping(Request $request)
    {
        $validated = $request->validate([
            'shop_id' => ['required', 'string'],
            'order_sn' => ['required', 'string'],
            'address_id' => ['required'],
            'pickup_time_id' => ['required'],
        ]);

        try {
            $result = $this->orderService->updateShippingOrder($validated['shop_id'], $validated['order_sn'], [
                'address_id' => $validated['address_id'],
                'pickup_time_id' => $validated['pickup_time_id'],
            ]);

            if (empty($result['updated'])) {
                return $this->errorResponse('Gagal retry pengiriman: ' . ($result['error'] ?? 'unknown'), 422, $result);
            }

            return $this->successResponse($result, 'Pengiriman Shopee diperbarui.');
        } catch (\Throwable $e) {
            return $this->errorResponse(
                'Gagal retry pengiriman.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Post(path: '/api/v1/shopee/sync/handle-buyer-cancel', summary: 'Tanggapi permintaan batal pembeli Shopee', tags: ['Shopee'])]
    public function handleBuyerCancel(Request $request)
    {
        $validated = $request->validate([
            'shop_id' => ['required', 'string'],
            'order_sn' => ['required', 'string'],
            'operation' => ['required', 'string', 'in:ACCEPT,REJECT'],
        ]);

        try {
            $result = $this->orderService->handleBuyerCancellation($validated['shop_id'], $validated['order_sn'], $validated['operation']);

            if (empty($result['handled'])) {
                return $this->errorResponse('Gagal menanggapi pembatalan: ' . ($result['error'] ?? 'unknown'), 422, $result);
            }

            return $this->successResponse($result, 'Permintaan pembatalan pembeli ditanggapi.');
        } catch (\Throwable $e) {
            return $this->errorResponse(
                'Gagal menanggapi pembatalan.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Post(path: '/api/v1/shopee/sync/split', summary: 'Pisah order Shopee', tags: ['Shopee'])]
    public function splitOrder(Request $request)
    {
        $validated = $request->validate([
            'shop_id' => ['required', 'string'],
            'order_sn' => ['required', 'string'],
            'package_list' => ['required', 'array', 'min:1'],
        ]);

        try {
            $result = $this->orderService->splitOrder($validated['shop_id'], $validated['order_sn'], $validated['package_list']);

            if (empty($result['split'])) {
                return $this->errorResponse('Gagal memisah order: ' . ($result['error'] ?? 'unknown'), 422, $result);
            }

            return $this->successResponse($result, 'Order Shopee dipisah.');
        } catch (\Throwable $e) {
            return $this->errorResponse(
                'Gagal memisah order.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Post(path: '/api/v1/shopee/sync/unsplit', summary: 'Gabung kembali order Shopee', tags: ['Shopee'])]
    public function unsplitOrder(Request $request)
    {
        $validated = $request->validate([
            'shop_id' => ['required', 'string'],
            'order_sn' => ['required', 'string'],
        ]);

        try {
            $result = $this->orderService->unsplitOrder($validated['shop_id'], $validated['order_sn']);

            if (empty($result['unsplit'])) {
                return $this->errorResponse('Gagal menggabung order: ' . ($result['error'] ?? 'unknown'), 422, $result);
            }

            return $this->successResponse($result, 'Order Shopee digabung kembali.');
        } catch (\Throwable $e) {
            return $this->errorResponse(
                'Gagal menggabung order.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Post(path: '/api/v1/shopee/sync/cancel', summary: 'Batalkan order Shopee', tags: ['Shopee'])]
    public function cancelOrder(Request $request)
    {
        $validated = $request->validate([
            'shop_id' => ['required', 'string'],
            'order_sn' => ['required', 'string'],
            'cancel_reason' => ['required', 'string'],
        ]);

        try {
            $result = $this->orderService->cancelOrder($validated['shop_id'], $validated['order_sn'], $validated['cancel_reason']);

            return $this->successResponse($result, 'Order Shopee berhasil dibatalkan.');
        } catch (\Throwable $e) {
            return $this->errorResponse(
                'Gagal membatalkan order.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Get(path: '/api/v1/shopee/cancel-reasons', summary: 'Daftar alasan pembatalan Shopee (enum tetap)', tags: ['Shopee'])]
    public function cancelReasons()
    {
        return $this->successResponse($this->orderService->getCancelReasons(), 'Daftar alasan pembatalan Shopee.');
    }

    #[OA\Get(path: '/api/v1/shopee/logistics', summary: 'Daftar kurir/logistik Shopee', tags: ['Shopee'])]
    public function logistics(Request $request)
    {
        $validated = $request->validate(['shop_id' => ['required', 'string']]);

        try {
            return $this->successResponse($this->orderService->getLogistics($validated['shop_id']), 'Daftar kurir Shopee.');
        } catch (\Throwable $e) {
            return $this->errorResponse(
                'Gagal memuat riwayat.',
                422,
                ['detail' => $e->getMessage()],
                'Terjadi kesalahan',
            );
        }
    }

    #[OA\Post(path: '/api/v1/shopee/sync/products/push', summary: 'Dorong produk ke Shopee', tags: ['Shopee'])]
    public function pushProduct(Request $request, ShopeeProductService $productService)
    {
        $validated = $request->validate([
            'shop_id' => ['required', 'string'],
            'product_id' => ['required', 'uuid'],
        ]);

        $result = $productService->pushProductListing($validated['shop_id'], $validated['product_id']);

        if (! ($result['ok'] ?? false)) {
            return $this->errorResponse($result['message'], $result['code'], $result['errors'] ?? null);
        }

        return $this->successResponse(
            ['external_product_id' => $result['external_product_id']],
            'Produk berhasil didorong ke Shopee (menunggu review).'
        );
    }

    #[OA\Post(path: '/api/v1/shopee/sync/categories', summary: 'Sinkron pohon kategori Shopee', tags: ['Shopee'])]
    public function syncCategories(Request $request, ShopeeProductService $productService)
    {
        $validated = $request->validate(['shop_id' => ['required', 'string']]);

        try {
            $count = $productService->syncCategoryTree($validated['shop_id']);
        } catch (\Throwable $e) {
            return $this->errorResponse(
                'Gagal sinkron kategori Shopee.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }

        return $this->successResponse(['synced' => $count], "{$count} kategori Shopee disinkronkan.");
    }

    #[OA\Post(path: '/api/v1/shopee/sync/category-attributes', summary: 'Sinkron atribut kategori Shopee', tags: ['Shopee'])]
    public function syncCategoryAttributes(Request $request, ShopeeProductService $productService)
    {
        $validated = $request->validate([
            'shop_id' => ['required', 'string'],
            'category_id' => ['nullable', 'string'],
        ]);

        try {
            if (! empty($validated['category_id'])) {
                $count = $productService->syncCategoryAttributes($validated['shop_id'], $validated['category_id']);

                return $this->successResponse(
                    ['synced' => $count, 'category_id' => $validated['category_id']],
                    "{$count} atribut Shopee disinkronkan."
                );
            }

            $results = $productService->syncAllMappedCategoryAttributes($validated['shop_id']);
        } catch (\Throwable $e) {
            return $this->errorResponse(
                'Gagal sinkron atribut Shopee.',
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

    #[OA\Get(path: '/api/v1/shopee/products/{item}/models', summary: 'Daftar varian/model item Shopee', tags: ['Shopee'])]
    public function getModels(Request $request, string $item, ShopeeProductService $productService)
    {
        $validated = $request->validate(['shop_id' => ['required', 'string']]);

        try {
            return $this->successResponse($productService->getModelList($validated['shop_id'], $item), 'Daftar varian Shopee berhasil diambil.');
        } catch (\Throwable $e) {
            return $this->errorResponse(
                'Gagal memuat data.',
                422,
                ['detail' => $e->getMessage()],
                'Terjadi kesalahan',
            );
        }
    }
}
