<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\Channel\Jobs\SyncProductToChannelJob;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Services\WooCommerceOrderService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'WooCommerce', description: 'Integrasi WooCommerce')]
class WooCommerceSyncApiController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected WooCommerceOrderService $orderService,
        protected ChannelShopRepository $shopRepository,
    ) {}

    public function pullOrders(Request $request)
    {
        $validated = $request->validate([
            'shop_id' => ['required', 'string'],
            'updated_after' => ['nullable', 'integer'],
        ]);

        try {
            $count = $this->orderService->pullOrders($validated['shop_id'], $validated['updated_after'] ?? null);

            return $this->successResponse(['pulled' => $count], "{$count} pesanan WooCommerce ditarik");
        } catch (\Throwable $e) {
            return $this->errorResponse(
                'Gagal menyinkronkan.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    public function pullOrdersAll(Request $request)
    {
        $updatedAfter = $request->integer('updated_after') ?: null;

        $shops = $this->shopRepository->getShopsByChannelCode('woocommerce')
            ->where('is_active', true);

        $total = 0;
        $errors = [];

        foreach ($shops as $shop) {
            try {
                $total += $this->orderService->pullOrders($shop->shop_id, $updatedAfter);
                $this->shopRepository->markOrderSyncOk($shop->id);
            } catch (\Throwable $e) {
                $this->shopRepository->markOrderSyncProblem($shop->id, $e->getMessage());
                $errors[] = ['shop_id' => $shop->shop_id, 'error' => $e->getMessage()];
            }
        }

        return $this->successResponse(['pulled' => $total, 'errors' => $errors], "{$total} pesanan WooCommerce ditarik");
    }

    public function shipOrder(Request $request)
    {
        $validated = $request->validate([
            'shop_id' => ['required', 'string'],
            'order_id' => ['required', 'string'],
            'tracking_number' => ['nullable', 'string'],
            'shipping_provider' => ['nullable', 'string'],
        ]);

        try {
            $result = $this->orderService->shipOrder(
                $validated['shop_id'],
                $validated['order_id'],
                $validated['tracking_number'] ?? null,
                $validated['shipping_provider'] ?? null,
            );

            return $this->successResponse($result, $result['message']);
        } catch (\Throwable $e) {
            return $this->errorResponse(
                'Gagal memproses pengiriman.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    public function cancelOrder(Request $request)
    {
        $validated = $request->validate([
            'shop_id' => ['required', 'string'],
            'order_id' => ['required', 'string'],
            'reason' => ['nullable', 'string'],
        ]);

        try {
            $result = $this->orderService->cancelOrder(
                $validated['shop_id'],
                $validated['order_id'],
                $validated['reason'] ?? null,
            );

            return $this->successResponse($result, $result['message']);
        } catch (\Throwable $e) {
            return $this->errorResponse(
                'Gagal membatalkan.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    public function pushProduct(Request $request)
    {
        $validated = $request->validate([
            'product_id' => ['required', 'string'],
            'channel_shop_id' => ['required', 'string'],
        ]);

        SyncProductToChannelJob::dispatch($validated['product_id'], $validated['channel_shop_id'], 'push');

        return $this->successResponse(null, 'Produk WooCommerce sedang didorong');
    }
}
