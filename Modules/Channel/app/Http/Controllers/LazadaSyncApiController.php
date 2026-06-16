<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\Channel\Adapters\LazadaAdapter;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Services\ChannelListingValidator;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Channel\Services\LazadaProductService;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Lazada', description: 'Integrasi OAuth Lazada')]
class LazadaSyncApiController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected LazadaOrderService $orderService,
        protected ChannelShopRepository $shopRepository,
    ) {}

    /** Push satu produk Master ke Lazada (listing). Kembalikan respons Lazada apa adanya. */
    public function pushProduct(Request $request)
    {
        $validated = $request->validate([
            'shop_id' => 'required|string',
            'product_id' => 'required|uuid',
        ]);

        $shop = ChannelShop::where('shop_id', $validated['shop_id'])
            ->whereNull('disconnected_at')
            ->first();
        if (! $shop) {
            return $this->errorResponse('Toko Lazada tidak ditemukan / terputus', 404);
        }

        $product = Product::with(['variants', 'media'])->find($validated['product_id']);
        if (! $product) {
            return $this->errorResponse('Produk tidak ditemukan', 404);
        }

        // Pre-flight: cegah penolakan marketplace untuk hal yang bisa dicek lokal.
        $issues = app(ChannelListingValidator::class)->validate($product, 'lazada');
        if (! empty($issues)) {
            return $this->errorResponse('Produk belum siap di-listing ke Lazada', 422, ['issues' => $issues]);
        }

        $result = app(LazadaAdapter::class)->pushProduct($product, $shop);

        if (! ($result['success'] ?? false)) {
            // Pesan asli Lazada untuk debugging mapper/atribut/kategori.
            return $this->errorResponse($result['message'] ?? 'Gagal push ke Lazada', 422, $result);
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
            'Produk berhasil didorong ke Lazada (menunggu review).'
        );
    }

    /** Sinkronkan pohon kategori Lazada ke channel_categories (prasyarat mapping kategori). */
    public function syncCategories(Request $request, LazadaProductService $productService)
    {
        $validated = $request->validate(['shop_id' => 'required|string']);

        try {
            $count = $productService->syncCategoryTree($validated['shop_id']);
        } catch (\Throwable $e) {
            return $this->errorResponse('Gagal sinkron kategori Lazada: ' . $e->getMessage(), 422);
        }

        return $this->successResponse(['synced' => $count], "{$count} kategori Lazada disinkronkan.");
    }

    /**
     * Pre-flight kesiapan listing tanpa mengirim ke marketplace (untuk FE checklist).
     */
    public function validateListing(Request $request, ChannelListingValidator $validator)
    {
        $validated = $request->validate(['product_id' => 'required|uuid']);

        $product = Product::find($validated['product_id']);
        if (! $product) {
            return $this->errorResponse('Produk tidak ditemukan', 404);
        }

        $issues = $validator->validate($product, 'lazada');

        return $this->successResponse(
            ['ready' => empty($issues), 'issues' => $issues],
            empty($issues) ? 'Produk siap di-listing ke Lazada.' : 'Produk belum siap di-listing.'
        );
    }

    /**
     * Sinkronkan spec atribut Lazada per kategori (channel_attributes).
     * Tanpa category_id → semua kategori yang sudah dipetakan.
     */
    public function syncCategoryAttributes(Request $request, LazadaProductService $productService)
    {
        $validated = $request->validate([
            'shop_id' => 'required|string',
            'category_id' => 'nullable|string',
        ]);

        try {
            if (! empty($validated['category_id'])) {
                $count = $productService->syncCategoryAttributes($validated['shop_id'], $validated['category_id']);

                return $this->successResponse(
                    ['synced' => $count, 'category_id' => $validated['category_id']],
                    "{$count} atribut Lazada disinkronkan."
                );
            }

            $results = $productService->syncAllMappedCategoryAttributes($validated['shop_id']);
        } catch (\Throwable $e) {
            return $this->errorResponse('Gagal sinkron atribut Lazada: ' . $e->getMessage(), 422);
        }

        return $this->successResponse(
            ['categories' => $results, 'total' => array_sum($results)],
            array_sum($results) . ' atribut dari ' . count($results) . ' kategori disinkronkan.'
        );
    }

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

    #[OA\Post(
        path: '/api/v1/lazada/sync/pack',
        summary: 'Terima order: pack + Ready-to-Ship',
        security: [['bearerAuth' => []]],
        tags: ['Lazada'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['shop_id', 'order_id'],
                properties: [
                    new OA\Property(property: 'shop_id', type: 'string'),
                    new OA\Property(property: 'order_id', type: 'string'),
                    new OA\Property(property: 'shipping_provider', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Order diproses (pack + RTS)'),
            new OA\Response(response: 422, description: 'Gagal'),
        ]
    )]
    public function packOrder(Request $request)
    {
        $validated = $request->validate([
            'shop_id' => 'required|string',
            'order_id' => 'required|string',
            'shipping_provider' => 'nullable|string',
        ]);

        try {
            $result = $this->orderService->packOrder(
                $validated['shop_id'],
                $validated['order_id'],
                $validated['shipping_provider'] ?? null
            );

            return $this->successResponse($result, 'Order berhasil diproses (pack + ready-to-ship).');
        } catch (\Exception $e) {
            return $this->errorResponse('Gagal memproses order: ' . $e->getMessage(), 422);
        }
    }

    #[OA\Post(
        path: '/api/v1/lazada/sync/cancel',
        summary: 'Batalkan order Lazada',
        security: [['bearerAuth' => []]],
        tags: ['Lazada'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['shop_id', 'order_id', 'reason_id'],
                properties: [
                    new OA\Property(property: 'shop_id', type: 'string'),
                    new OA\Property(property: 'order_id', type: 'string'),
                    new OA\Property(property: 'reason_id', type: 'string', description: 'Dari GET /lazada/cancel-reasons'),
                    new OA\Property(property: 'reason_detail', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Order dibatalkan'),
            new OA\Response(response: 422, description: 'Gagal'),
        ]
    )]
    public function cancelOrder(Request $request)
    {
        $validated = $request->validate([
            'shop_id' => 'required|string',
            'order_id' => 'required|string',
            'reason_id' => 'required|string',
            'reason_detail' => 'nullable|string',
        ]);

        try {
            $result = $this->orderService->cancelOrder(
                $validated['shop_id'],
                $validated['order_id'],
                $validated['reason_id'],
                $validated['reason_detail'] ?? null
            );

            return $this->successResponse($result, 'Order berhasil dibatalkan.');
        } catch (\Exception $e) {
            return $this->errorResponse('Gagal membatalkan order: ' . $e->getMessage(), 422);
        }
    }

    #[OA\Get(
        path: '/api/v1/lazada/cancel-reasons',
        summary: 'Daftar alasan pembatalan Lazada',
        security: [['bearerAuth' => []]],
        tags: ['Lazada'],
        parameters: [new OA\Parameter(name: 'shop_id', in: 'query', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'OK')]
    )]
    public function cancelReasons(Request $request)
    {
        $validated = $request->validate(['shop_id' => 'required|string']);

        try {
            return $this->successResponse(
                $this->orderService->getCancelReasons($validated['shop_id']),
                'Daftar alasan pembatalan berhasil diambil.'
            );
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    #[OA\Get(
        path: '/api/v1/lazada/logistics',
        summary: 'Daftar kurir/logistik channel Lazada',
        security: [['bearerAuth' => []]],
        tags: ['Lazada'],
        parameters: [new OA\Parameter(name: 'shop_id', in: 'query', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'OK')]
    )]
    public function logistics(Request $request)
    {
        $validated = $request->validate(['shop_id' => 'required|string']);

        try {
            return $this->successResponse(
                $this->orderService->getShipmentProviders($validated['shop_id']),
                'Daftar kurir Lazada berhasil diambil.'
            );
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }
}
