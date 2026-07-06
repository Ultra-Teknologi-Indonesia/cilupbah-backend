<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\Channel\Adapters\LazadaAdapter;
use Modules\Channel\Jobs\ProcessLazadaFulfillmentJob;
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

        $issues = app(ChannelListingValidator::class)->validate($product, 'lazada');
        if (! empty($issues)) {
            return $this->errorResponse('Produk belum siap di-listing ke Lazada', 422, ['issues' => $issues]);
        }

        $result = app(LazadaAdapter::class)->pushProduct($product, $shop);

        if (! ($result['success'] ?? false)) {

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
        path: '/api/v1/lazada/sync/fulfill-pack',
        summary: 'Pack order (hanya item pending/repacked)',
        security: [['bearerAuth' => []]],
        tags: ['Lazada'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['shop_id', 'order_id', 'shipping_provider_id'],
                properties: [
                    new OA\Property(property: 'shop_id', type: 'string'),
                    new OA\Property(property: 'order_id', type: 'string'),
                    new OA\Property(property: 'shipping_provider_id', type: 'string', description: 'Dari GET /lazada/logistics'),
                    new OA\Property(property: 'delivery_type', type: 'string', nullable: true, description: 'Default: dropship'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Order berhasil di-pack'),
            new OA\Response(response: 422, description: 'Gagal'),
        ]
    )]
    public function fulfillPack(Request $request)
    {
        $validated = $request->validate([
            'shop_id' => 'required|string',
            'order_id' => 'required|string',
            'shipping_provider_id' => 'required|string',
            'delivery_type' => 'nullable|string',
        ]);

        try {
            $result = $this->orderService->fulfillPack(
                $validated['shop_id'],
                $validated['order_id'],
                $validated['shipping_provider_id'],
                $validated['delivery_type'] ?? 'dropship'
            );

            return $this->successResponse($result, 'Order berhasil di-pack.');
        } catch (\Exception $e) {
            return $this->errorResponse('Gagal memproses pack: ' . $e->getMessage(), 422);
        }
    }

    #[OA\Post(
        path: '/api/v1/lazada/sync/fulfill',
        summary: 'Proses order otomatis: pack -> AWB -> RTS (antrean, dengan retry)',
        security: [['bearerAuth' => []]],
        tags: ['Lazada'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['shop_id', 'order_id', 'shipping_provider_id'],
                properties: [
                    new OA\Property(property: 'shop_id', type: 'string'),
                    new OA\Property(property: 'order_id', type: 'string'),
                    new OA\Property(property: 'shipping_provider_id', type: 'string'),
                    new OA\Property(property: 'delivery_type', type: 'string', nullable: true),
                    new OA\Property(property: 'tracking_number', type: 'string', nullable: true),
                    new OA\Property(property: 'package_id', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [new OA\Response(response: 202, description: 'Antrean fulfillment dijadwalkan')]
    )]
    public function processFulfillment(Request $request)
    {
        $validated = $request->validate([
            'shop_id' => 'required|string',
            'order_id' => 'required|string',
            'shipping_provider_id' => 'required|string',
            'delivery_type' => 'nullable|string',
            'tracking_number' => 'nullable|string',
            'package_id' => 'nullable|string',
        ]);

        ProcessLazadaFulfillmentJob::dispatch(
            $validated['shop_id'],
            $validated['order_id'],
            $validated['shipping_provider_id'],
            $validated['delivery_type'] ?? 'dropship',
            $validated['tracking_number'] ?? null,
            $validated['package_id'] ?? null,
        )->afterCommit();

        return $this->successResponse(['order_id' => $validated['order_id']], 'Fulfillment order dijadwalkan diproses.');
    }

    #[OA\Get(
        path: '/api/v1/lazada/sync/awb',
        summary: 'Cetak AWB/shipping label (polling sampai siap)',
        security: [['bearerAuth' => []]],
        tags: ['Lazada'],
        parameters: [
            new OA\Parameter(name: 'shop_id', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'order_id', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Dokumen AWB siap'),
            new OA\Response(response: 422, description: 'AWB belum siap / gagal'),
        ]
    )]
    public function printAwb(Request $request)
    {
        $validated = $request->validate([
            'shop_id' => 'required|string',
            'order_id' => 'required|string',
        ]);

        try {
            $result = $this->orderService->printAwb($validated['shop_id'], $validated['order_id']);

            return $this->successResponse($result, 'Dokumen AWB berhasil diambil.');
        } catch (\Exception $e) {
            return $this->errorResponse('Gagal mengambil AWB: ' . $e->getMessage(), 422);
        }
    }

    #[OA\Post(
        path: '/api/v1/lazada/sync/rts',
        summary: 'Ready-to-Ship (hanya item packed)',
        security: [['bearerAuth' => []]],
        tags: ['Lazada'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['shop_id', 'order_id'],
                properties: [
                    new OA\Property(property: 'shop_id', type: 'string'),
                    new OA\Property(property: 'order_id', type: 'string'),
                    new OA\Property(property: 'tracking_number', type: 'string', nullable: true),
                    new OA\Property(property: 'package_id', type: 'string', nullable: true),
                    new OA\Property(property: 'delivery_type', type: 'string', nullable: true, description: 'Default: dropship'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Order ready-to-ship'),
            new OA\Response(response: 422, description: 'Gagal'),
        ]
    )]
    public function readyToShip(Request $request)
    {
        $validated = $request->validate([
            'shop_id' => 'required|string',
            'order_id' => 'required|string',
            'tracking_number' => 'nullable|string',
            'package_id' => 'nullable|string',
            'delivery_type' => 'nullable|string',
        ]);

        try {
            $result = $this->orderService->readyToShip(
                $validated['shop_id'],
                $validated['order_id'],
                $validated['tracking_number'] ?? null,
                $validated['package_id'] ?? null,
                $validated['delivery_type'] ?? 'dropship'
            );

            return $this->successResponse($result, 'Order berhasil ready-to-ship.');
        } catch (\Exception $e) {
            return $this->errorResponse('Gagal memproses ready-to-ship: ' . $e->getMessage(), 422);
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
