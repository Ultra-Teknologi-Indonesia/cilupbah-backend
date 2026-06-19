<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\Channel\Adapters\ShopeeAdapter;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Services\ChannelListingValidator;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
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
                $results[] = ['shop_id' => $shop->shop_id, 'synced' => $count];
                $total += $count;
            } catch (\Throwable $e) {
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
        ]);

        try {
            $result = $this->orderService->shipOrder($validated['shop_id'], $validated['order_sn']);

            return $this->successResponse($result, 'Order Shopee berhasil dikirim.');
        } catch (\Throwable $e) {
            return $this->errorResponse('Gagal mengirim order: ' . $e->getMessage(), 422);
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
            return $this->errorResponse('Gagal membatalkan order: ' . $e->getMessage(), 422);
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
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    #[OA\Post(path: '/api/v1/shopee/sync/products/push', summary: 'Dorong produk ke Shopee', tags: ['Shopee'])]
    public function pushProduct(Request $request)
    {
        $validated = $request->validate([
            'shop_id' => ['required', 'string'],
            'product_id' => ['required', 'uuid'],
        ]);

        $shop = ChannelShop::where('shop_id', $validated['shop_id'])->whereNull('disconnected_at')->first();
        if (! $shop) {
            return $this->errorResponse('Toko Shopee tidak ditemukan / terputus', 404);
        }

        $product = Product::with(['variants', 'media'])->find($validated['product_id']);
        if (! $product) {
            return $this->errorResponse('Produk tidak ditemukan', 404);
        }

        $issues = app(ChannelListingValidator::class)->validate($product, 'shopee');
        if (! empty($issues)) {
            return $this->errorResponse('Produk belum siap di-listing ke Shopee', 422, ['issues' => $issues]);
        }

        $result = app(ShopeeAdapter::class)->pushProduct($product, $shop);

        if (! ($result['success'] ?? false)) {
            return $this->errorResponse($result['message'] ?? 'Gagal push ke Shopee', 422, $result);
        }

        $externalId = $result['external_product_id'] ?? null;

        $mapping = ProductChannelMapping::firstOrNew([
            'product_id' => $product->id,
            'channel_shop_id' => $shop->id,
        ]);
        $mapping->external_product_id = $externalId;
        $mapping->save();
        $mapping->markInReview($externalId);

        return $this->successResponse(
            ['external_product_id' => $externalId],
            'Produk berhasil didorong ke Shopee (menunggu review).'
        );
    }
}
