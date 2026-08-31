<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Http\Requests\AcceptStockReplenishmentRequest;
use Modules\Inventory\Http\Requests\QueueStockReplenishmentRequest;
use Modules\Inventory\Http\Requests\RejectStockReplenishmentRequest;
use Modules\Inventory\Http\Resources\StockReplenishmentItemResource;
use Modules\Inventory\Http\Resources\StockReplenishmentResource;
use Modules\Inventory\Services\StockReplenishmentService;

class StockReplenishmentController extends Controller
{
    use ApiResponse;

    public function __construct(private StockReplenishmentService $service) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        $paginator = $this->service->list($status, $perPage);

        return $this->successResponse([
            'items' => StockReplenishmentResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ], 'Daftar permintaan pengisian stok');
    }

    public function pendingCount(): JsonResponse
    {
        $count = $this->service->pendingCount();

        return $this->successResponse(['count' => $count], 'Jumlah permintaan pending');
    }

    public function show(string $id): JsonResponse
    {
        $req = $this->service->findDetail($id);

        if (! $req) {
            return $this->errorResponse('Permintaan tidak ditemukan', 404);
        }

        return $this->successResponse(
            new StockReplenishmentResource($req),
            'Detail permintaan pengisian stok',
        );
    }

    public function items(string $id, Request $request): JsonResponse
    {
        if (! $this->service->findDetail($id)) {
            return $this->errorResponse('Permintaan tidak ditemukan', 404);
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'channel' => ['nullable', 'string', 'max:80'],
            'shop_id' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $perPage = (int) ($validated['per_page'] ?? 20);
        $paginator = $this->service->paginateItems(
            $id,
            $validated['search'] ?? null,
            $validated['channel'] ?? null,
            $validated['shop_id'] ?? null,
            $perPage,
        );

        return $this->successResponse(
            StockReplenishmentItemResource::collection($paginator->items()),
            'Daftar item permintaan pengisian stok',
            200,
            [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        );
    }

    public function itemFilterOptions(string $id): JsonResponse
    {
        if (! $this->service->findDetail($id)) {
            return $this->errorResponse('Permintaan tidak ditemukan', 404);
        }

        return $this->successResponse(
            $this->service->itemFilterOptions($id),
            'Filter item permintaan pengisian stok',
        );
    }

    public function store(): JsonResponse
    {
        return $this->errorResponse(
            'Permintaan hanya dapat dibuat dari Monitor Stok > Dipesan namun habis.',
            422,
            [],
            'Sumber permintaan tidak valid',
        );
    }

    public function queueFromMonitor(QueueStockReplenishmentRequest $request): JsonResponse
    {
        try {
            $result = $this->service->queueFromMonitor($request->validated());
        } catch (\RuntimeException $e) {
            return $this->errorResponse(
                'Gagal memasukkan produk ke antrian restock.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }

        return $this->successResponse([
            'request' => $result['request']
                ? new StockReplenishmentResource($result['request'])
                : null,
            'queued_item_ids' => $result['queued'],
            'skipped_item_ids' => $result['skipped'],
        ], 'Produk dimasukkan ke antrian permintaan restock');
    }

    public function accept(string $id, AcceptStockReplenishmentRequest $request): JsonResponse
    {
        try {
            $req = $this->service->accept(
                $id,
                $request->input('assignee_user_id'),
                $request->input('note'),
            );
        } catch (\RuntimeException $e) {
            return $this->errorResponse(
                'Gagal memproses penerimaan.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }

        return $this->successResponse(
            new StockReplenishmentResource($req->load(['items', 'fromLocation', 'toLocation', 'assignee', 'transferOut'])),
            'Permintaan disetujui',
        );
    }

    public function reject(string $id, RejectStockReplenishmentRequest $request): JsonResponse
    {
        try {
            $req = $this->service->reject($id, $request->validated('reason'));
        } catch (\RuntimeException $e) {
            return $this->errorResponse(
                'Gagal menolak.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }

        return $this->successResponse(
            new StockReplenishmentResource($req->load(['items', 'rejecter'])),
            'Permintaan ditolak',
        );
    }

    public function addItem(): JsonResponse
    {
        return $this->errorResponse(
            'SKU hanya dapat ditambahkan dari Monitor Stok > Dipesan namun habis.',
            422,
            [],
            'Sumber item tidak valid',
        );
    }

    public function updateItem(string $id, string $itemId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'qty' => ['sometimes', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->service->updateItem($id, $itemId, $validated);
        } catch (\RuntimeException $e) {
            return $this->errorResponse(
                'Gagal memperbarui.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }

        return $this->successResponse(
            $this->reloadDetail($id),
            'Item diperbarui',
        );
    }

    public function removeItem(string $id, string $itemId): JsonResponse
    {
        try {
            $this->service->removeItem($id, $itemId);
        } catch (\RuntimeException $e) {
            return $this->errorResponse(
                'Gagal menghapus.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }

        return $this->successResponse(
            $this->reloadDetail($id),
            'Item dihapus',
        );
    }

    private function reloadDetail(string $id): StockReplenishmentResource
    {
        return new StockReplenishmentResource($this->service->findDetailOrFail($id));
    }
}
