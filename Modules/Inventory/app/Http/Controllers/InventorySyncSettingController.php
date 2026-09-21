<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Http\Resources\InventorySyncMatrixResource;
use Modules\Inventory\Services\InventorySyncSettingService;
use Modules\Product\Http\Resources\ProductSyncLogResource;
use Modules\Product\Models\ProductChannelMapping;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Sync Stok & Harga', description: 'Pengaturan Persediaan: toggle sync stok & harga per SKU × store')]
class InventorySyncSettingController extends Controller
{
    public function __construct(
        protected InventorySyncSettingService $service,
    ) {}

    #[OA\Get(
        path: '/api/v1/inventory/sync-settings',
        summary: 'Matriks Produk (SKU) × Store dengan status sync stok & harga',
        security: [['bearerAuth' => []]],
        tags: ['Sync Stok & Harga'],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Cari SKU, nama produk, atau SKU produk induk.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[channel_code]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[channel_shop_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'filter[sync_status]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[stock_sync_status]', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'dispatching', 'succeeded', 'failed', 'skipped'])),
            new OA\Parameter(name: 'filter[sync_enabled]', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'filter[is_bundle]', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'filter[has_listing]', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, description: 'sku, created_at, updated_at; prefix - untuk descending.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20, maximum: 100)),
        ],
        responses: [new OA\Response(response: 200, description: 'Matriks sync stok & harga.')]
    )]
    public function index(Request $request): JsonResponse
    {
        $filters = $this->service->filtersFrom($request->query());
        $perPage = min(max((int) ($request->query('per_page') ?? $request->query('limit') ?? 20), 1), 100);

        $paginator = $this->service->matrix($filters, $perPage, $request);

        return $this->successResponse(
            InventorySyncMatrixResource::collection($paginator->getCollection()),
            'Matriks sync stok & harga berhasil diambil.',
            200,
            [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'stores_catalog' => $this->service->storesCatalog($filters),
            ],
        );
    }

    #[OA\Patch(
        path: '/api/v1/inventory/sync-settings',
        summary: 'Toggle sync stok & harga per sel (SKU × store)',
        security: [['bearerAuth' => []]],
        tags: ['Sync Stok & Harga'],
        responses: [new OA\Response(response: 200, description: 'Toggle tersimpan.')]
    )]
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.variant_id' => ['required', 'uuid'],
            'items.*.channel_shop_id' => ['required', 'uuid'],
            'items.*.sync_enabled' => ['required', 'boolean'],
        ]);

        $affected = $this->service->toggle($validated['items']);

        return $this->successResponse(
            ['affected' => $affected],
            'Berhasil memperbarui sync stok & harga.',
        );
    }

    #[OA\Post(
        path: '/api/v1/inventory/sync-settings/bulk',
        summary: 'Toggle massal (per kolom store / lintas filter)',
        security: [['bearerAuth' => []]],
        tags: ['Sync Stok & Harga'],
        responses: [new OA\Response(response: 200, description: 'Toggle massal tersimpan.')]
    )]
    public function bulkUpdate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sync_enabled' => ['required', 'boolean'],
            'channel_shop_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string'],
            'channel_code' => ['nullable', 'string'],
        ]);

        $filters = $this->service->filtersFrom($validated);

        $affected = $this->service->bulkToggle(
            $validated['sync_enabled'],
            $filters,
            $validated['channel_shop_id'] ?? null,
        );

        return $this->successResponse(
            ['affected' => $affected],
            'Berhasil memperbarui sync stok & harga secara massal.',
        );
    }

    public function retry(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mapping_id' => ['required', 'uuid'],
        ]);

        try {
            $result = $this->service->retryMapping($validated['mapping_id']);
        } catch (ModelNotFoundException) {
            return $this->errorResponse('Listing channel tidak ditemukan.', 404);
        } catch (DomainException $e) {
            return $this->errorResponse(
                $e->getMessage(),
                422,
                null,
                'Sinkronisasi tidak dapat diproses',
            );
        }

        return $this->successResponse($result, 'Sinkronisasi stok diantrekan.', 202);
    }

    public function history(Request $request, string $mapping): JsonResponse
    {
        $channelMapping = ProductChannelMapping::query()->findOrFail($mapping);
        $perPage = min(max((int) $request->query('per_page', 10), 1), 25);

        $paginator = $this->service->history($channelMapping, $perPage, $request);

        $paginator->setCollection(
            $paginator->getCollection()->map(
                fn (ProductSyncLog $log) => (new ProductSyncLogResource($log))->resolve($request)
            )
        );

        return $this->successPaginatedResponse($paginator, 'Riwayat sinkronisasi stok berhasil diambil.');
    }
}
