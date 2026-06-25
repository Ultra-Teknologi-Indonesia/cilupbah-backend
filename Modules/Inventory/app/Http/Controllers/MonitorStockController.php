<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Http\Resources\FailedSyncResource;
use Modules\Inventory\Http\Resources\MonitorAnalyticsResource;
use Modules\Inventory\Http\Resources\MonitorStockResource;
use Modules\Inventory\Services\MonitorStockService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Monitor Stok', description: 'Dashboard pengawasan persediaan (read-only)')]
class MonitorStockController extends Controller
{
    public function __construct(
        protected MonitorStockService $service,
    ) {}

    private function perPage(Request $request): int
    {
        return (int) ($request->query('per_page') ?? $request->query('limit') ?? 10);
    }

    private function filters(Request $request): array
    {
        return $this->service->filtersFrom($request->query());
    }

    #[OA\Get(
        path: '/api/v1/inventory/monitor/summary',
        summary: 'Jumlah item per tab Monitor Stok (badge Total)',
        security: [['bearerAuth' => []]],
        tags: ['Monitor Stok'],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'location_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'brand_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'category_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Ringkasan monitor stok.')]
    )]
    public function summary(Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->service->summary($this->filters($request)),
            'Ringkasan monitor stok berhasil diambil.'
        );
    }

    #[OA\Get(
        path: '/api/v1/inventory/monitor/out-of-stock',
        summary: 'Tab Stok Kosong (mode: habis | minus | dipesan)',
        security: [['bearerAuth' => []]],
        tags: ['Monitor Stok'],
        parameters: [
            new OA\Parameter(name: 'mode', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['habis', 'minus', 'dipesan'], default: 'habis')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'location_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'brand_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'category_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [new OA\Response(response: 200, description: 'Daftar produk stok kosong.')]
    )]
    public function outOfStock(Request $request): JsonResponse
    {
        $mode = (string) $request->query('mode', 'habis');
        $data = $this->service->outOfStock($mode, $this->filters($request), $this->perPage($request));

        return $this->successPaginatedResponse(
            MonitorStockResource::collection($data),
            'Daftar produk stok kosong berhasil diambil.'
        );
    }

    #[OA\Get(
        path: '/api/v1/inventory/monitor/low-stock',
        summary: 'Tab Menipis (available < min_stock; target restock = safe_stock)',
        security: [['bearerAuth' => []]],
        tags: ['Monitor Stok'],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'location_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'brand_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'category_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [new OA\Response(response: 200, description: 'Daftar produk menipis.')]
    )]
    public function lowStock(Request $request): JsonResponse
    {
        $data = $this->service->lowStock($this->filters($request), $this->perPage($request));

        return $this->successPaginatedResponse(
            MonitorStockResource::collection($data),
            'Daftar produk menipis berhasil diambil.'
        );
    }

    #[OA\Get(
        path: '/api/v1/inventory/monitor/on-order',
        summary: 'Tab Sedang Dibeli (varian di PO berjalan)',
        security: [['bearerAuth' => []]],
        tags: ['Monitor Stok'],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'location_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'brand_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'category_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [new OA\Response(response: 200, description: 'Daftar produk sedang dibeli.')]
    )]
    public function onOrder(Request $request): JsonResponse
    {
        $data = $this->service->onOrder($this->filters($request), $this->perPage($request));

        return $this->successPaginatedResponse(
            MonitorStockResource::collection($data),
            'Daftar produk sedang dibeli berhasil diambil.'
        );
    }

    #[OA\Get(
        path: '/api/v1/inventory/monitor/dead-stock',
        summary: 'Tab Tidak Laku (tak terjual > N hari, masih ada stok)',
        security: [['bearerAuth' => []]],
        tags: ['Monitor Stok'],
        parameters: [
            new OA\Parameter(name: 'days', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 90)),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'location_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'brand_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'category_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [new OA\Response(response: 200, description: 'Daftar produk tidak laku.')]
    )]
    public function deadStock(Request $request): JsonResponse
    {
        $days = (int) $request->query('days', 90);
        $data = $this->service->deadStock($this->filters($request), $days, $this->perPage($request));

        return $this->successPaginatedResponse(
            MonitorAnalyticsResource::collection($data),
            'Daftar produk tidak laku berhasil diambil.'
        );
    }

    #[OA\Get(
        path: '/api/v1/inventory/monitor/fast-moving',
        summary: 'Tab Paling Laku (volume terjual window hari terakhir)',
        security: [['bearerAuth' => []]],
        tags: ['Monitor Stok'],
        parameters: [
            new OA\Parameter(name: 'days', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 30)),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'location_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'brand_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'category_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [new OA\Response(response: 200, description: 'Daftar produk paling laku.')]
    )]
    public function fastMoving(Request $request): JsonResponse
    {
        $days = (int) $request->query('days', 30);
        $data = $this->service->fastMoving($this->filters($request), $days, $this->perPage($request));

        return $this->successPaginatedResponse(
            MonitorAnalyticsResource::collection($data),
            'Daftar produk paling laku berhasil diambil.'
        );
    }

    #[OA\Get(
        path: '/api/v1/inventory/monitor/estimated-stock-out',
        summary: 'Tab Perkiraan Habis (proyeksi hari sampai stok habis)',
        security: [['bearerAuth' => []]],
        tags: ['Monitor Stok'],
        parameters: [
            new OA\Parameter(name: 'window', in: 'query', required: false, description: 'Window hari rata-rata penjualan', schema: new OA\Schema(type: 'integer', default: 30)),
            new OA\Parameter(name: 'threshold', in: 'query', required: false, description: 'Tampilkan bila days_to_out <= threshold', schema: new OA\Schema(type: 'integer', default: 30)),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'location_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'brand_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'category_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [new OA\Response(response: 200, description: 'Daftar produk perkiraan habis.')]
    )]
    public function estimatedStockOut(Request $request): JsonResponse
    {
        $window = (int) $request->query('window', 30);
        $threshold = (int) $request->query('threshold', 30);
        $data = $this->service->estimatedStockOut($this->filters($request), $window, $threshold, $this->perPage($request));

        return $this->successPaginatedResponse(
            MonitorAnalyticsResource::collection($data),
            'Daftar produk perkiraan habis berhasil diambil.'
        );
    }

    // ===================== Fase 4: Gagal Sync =====================

    #[OA\Get(
        path: '/api/v1/inventory/monitor/failed-sync',
        summary: 'Tab Gagal Sync (mapping channel berstatus failed)',
        security: [['bearerAuth' => []]],
        tags: ['Monitor Stok'],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'channel_shop_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [new OA\Response(response: 200, description: 'Daftar mapping gagal sync.')]
    )]
    public function failedSync(Request $request): JsonResponse
    {
        $data = $this->service->failedSync($this->perPage($request));

        return $this->successPaginatedResponse(
            FailedSyncResource::collection($data),
            'Daftar mapping gagal sync berhasil diambil.'
        );
    }

    #[OA\Post(
        path: '/api/v1/inventory/monitor/failed-sync/{id}/retry',
        summary: 'Retry sinkronisasi satu mapping yang gagal',
        security: [['bearerAuth' => []]],
        tags: ['Monitor Stok'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Retry berhasil dijadwalkan.')]
    )]
    public function retrySync(Request $request, string $id): JsonResponse
    {
        $mapping = $this->service->retrySync($id);

        return $this->successResponse(
            new FailedSyncResource($mapping),
            'Retry sinkronisasi berhasil dijadwalkan.'
        );
    }

    #[OA\Post(
        path: '/api/v1/inventory/monitor/failed-sync/retry-bulk',
        summary: 'Retry sinkronisasi beberapa mapping sekaligus',
        security: [['bearerAuth' => []]],
        tags: ['Monitor Stok'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['ids'],
                properties: [new OA\Property(property: 'ids', type: 'array', items: new OA\Items(type: 'string'))]
            )
        ),
        responses: [new OA\Response(response: 200, description: 'Retry bulk berhasil dijadwalkan.')]
    )]
    public function retryBulkSync(Request $request): JsonResponse
    {
        $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'string']);

        $count = $this->service->retryBulkSync($request->input('ids'));

        return $this->successResponse(
            ['retried' => $count],
            "{$count} mapping berhasil dijadwalkan untuk retry."
        );
    }
}
