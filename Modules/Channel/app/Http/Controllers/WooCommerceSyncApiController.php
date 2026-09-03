<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\Channel\Services\ChannelService;
use Modules\Channel\Services\WooCommerceOrderService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'WooCommerce', description: 'Integrasi WooCommerce')]
class WooCommerceSyncApiController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected WooCommerceOrderService $orderService,
        protected ChannelService $channelService,
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

        $summary = $this->channelService->syncOrdersForAllStores(
            'woocommerce',
            fn (string $shopId): int => $this->orderService->pullOrders($shopId, $updatedAfter),
        );
        $errors = collect($summary['stores'])
            ->filter(fn (array $store): bool => $store['status'] !== 'success')
            ->map(fn (array $store): array => [
                'shop_id' => $store['shop_id'],
                'error' => $store['error'],
            ])->values()->all();

        return $this->successResponse(['pulled' => $summary['total'], 'errors' => $errors], "{$summary['total']} pesanan WooCommerce ditarik");
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

        $this->channelService->queueProductPush($validated['product_id'], $validated['channel_shop_id']);

        return $this->successResponse(null, 'Produk WooCommerce sedang didorong');
    }
}
