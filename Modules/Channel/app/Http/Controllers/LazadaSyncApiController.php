<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Services\LazadaOrderService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Lazada', description: 'Integrasi OAuth Lazada')]
class LazadaSyncApiController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected LazadaOrderService $orderService,
        protected ChannelShopRepository $shopRepository,
    ) {}

    #[OA\Post(
        path: '/api/v1/lazada/sync/pull',
        summary: 'Tarik order Lazada satu toko',
        security: [['bearerAuth' => []]],
        tags: ['Lazada'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['shop_id'],
                properties: [
                    new OA\Property(property: 'shop_id', type: 'string', description: 'Seller ID Lazada (channel_shops.shop_id)'),
                    new OA\Property(property: 'update_after', type: 'string', nullable: true, description: 'ISO8601; default 7 hari terakhir'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Order ditarik'),
            new OA\Response(response: 422, description: 'Validasi/toko tidak terhubung'),
        ]
    )]
    public function pullOrders(Request $request)
    {
        $validated = $request->validate([
            'shop_id' => 'required|string',
            'update_after' => 'nullable|date',
        ]);

        try {
            $count = $this->orderService->pullOrders($validated['shop_id'], $validated['update_after'] ?? null);

            return $this->successResponse(['count' => $count], "Berhasil menarik {$count} pesanan Lazada.");
        } catch (\Exception $e) {
            return $this->errorResponse('Gagal menarik pesanan: ' . $e->getMessage(), 422);
        }
    }

    #[OA\Post(
        path: '/api/v1/lazada/auto-sync/pull-orders',
        summary: 'Tarik order semua toko Lazada aktif',
        security: [['bearerAuth' => []]],
        tags: ['Lazada'],
        responses: [new OA\Response(response: 200, description: 'Ringkasan per toko')]
    )]
    public function pullOrdersAll()
    {
        $shops = $this->shopRepository->getShopsByChannelCode('lazada')
            ->filter(fn ($shop) => $shop->is_active && $shop->access_token);

        $totalCount = 0;
        $results = [];

        foreach ($shops as $shop) {
            try {
                $count = $this->orderService->pullOrders($shop->shop_id);
                $totalCount += $count;
                $results[] = [
                    'shop_id' => $shop->shop_id,
                    'shop_name' => $shop->shop_name,
                    'status' => 'success',
                    'pulled_count' => $count,
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'shop_id' => $shop->shop_id,
                    'shop_name' => $shop->shop_name,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $this->successResponse(
            ['total_pulled' => $totalCount, 'shops' => $results],
            "Selesai: {$totalCount} pesanan ditarik dari " . count($results) . ' toko.'
        );
    }
}
