<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\Product\Http\Resources\ProductSyncLogResource;
use Modules\Product\Http\Resources\UploadHistoryResource;
use Modules\Product\Models\ProductSyncLog;
use Modules\Product\Services\DownloadHistoryService;
use Modules\Product\Services\UploadHistoryService;
use OpenApi\Attributes as OA;
use App\Traits\ApiResponse;

class ProductSyncLogController extends Controller
{
    use ApiResponse;

    public function __construct(
        private UploadHistoryService $uploadHistoryService,
        private DownloadHistoryService $downloadHistoryService,
    ) {}

    #[OA\Get(
        path: '/api/v1/upload-histories',
        summary: 'Riwayat upload produk ke channel',
        tags: ['Product Histories'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['success', 'failed', 'pending'])),
            new OA\Parameter(name: 'channel', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'shop_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Success')]
    )]
    public function uploadHistories(Request $request): JsonResponse
    {
        $paginator = $this->uploadHistoryService->paginate();

        $paginator->setCollection(
            $paginator->getCollection()->map(
                fn (ProductSyncLog $log) => (new UploadHistoryResource($log))->resolve($request)
            )
        );

        return $this->successPaginatedResponse($paginator, 'Get upload histories success');
    }

    /** Jubelio: GET /inventory/items/errors — Ambil daftar listing yang gagal upload. */
    public function errors(Request $request): JsonResponse
    {
        $paginator = $this->uploadHistoryService->paginateErrors();

        $paginator->setCollection(
            $paginator->getCollection()->map(
                fn (ProductSyncLog $log) => (new UploadHistoryResource($log))->resolve($request)
            )
        );

        return $this->successPaginatedResponse($paginator, 'Get upload errors success');
    }

    public function reupload(string $id): JsonResponse
    {
        try {
            $this->uploadHistoryService->reupload($id);
        } catch (ModelNotFoundException) {
            return $this->errorResponse('Riwayat upload tidak ditemukan', 404);
        } catch (DomainException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        return $this->successResponse(null, 'Produk diantrekan untuk upload ulang');
    }

    public function destroy(string $id): JsonResponse
    {
        try {
            $this->uploadHistoryService->delete($id);
        } catch (ModelNotFoundException) {
            return $this->errorResponse('Riwayat upload tidak ditemukan', 404);
        }

        return $this->successResponse(null, 'Riwayat upload dihapus');
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'uuid',
        ]);

        $deleted = $this->uploadHistoryService->bulkDelete($validated['ids']);

        return $this->successResponse(['deleted' => $deleted], "{$deleted} riwayat upload dihapus");
    }

    #[OA\Get(
        path: '/api/v1/download-histories',
        summary: 'Riwayat download produk dari channel',
        tags: ['Product Histories'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['success', 'failed', 'pending'])),
            new OA\Parameter(name: 'channel', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'shop_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [new OA\Response(response: 200, description: 'Success')]
    )]
    public function downloadHistories(Request $request): JsonResponse
    {
        $logs = $this->downloadHistoryService->paginate();

        return $this->successPaginatedResponse(
            ProductSyncLogResource::collection($logs),
            'Get download histories success'
        );
    }
}
