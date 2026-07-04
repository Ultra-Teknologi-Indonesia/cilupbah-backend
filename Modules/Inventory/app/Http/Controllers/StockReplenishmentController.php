<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Http\Requests\AcceptStockReplenishmentRequest;
use Modules\Inventory\Http\Requests\StoreStockReplenishmentRequest;
use Modules\Inventory\Http\Resources\StockReplenishmentResource;
use Modules\Inventory\Models\StockReplenishmentRequest;
use Modules\Inventory\Services\StockReplenishmentService;

class StockReplenishmentController extends Controller
{
    use ApiResponse;

    public function __construct(private StockReplenishmentService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $status  = $request->query('status');
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        $query = StockReplenishmentRequest::query()
            ->with(['items', 'fromLocation', 'toLocation', 'assignee', 'requester', 'transferOut'])
            ->orderByDesc('requested_at');

        if ($status) {
            $query->where('status', $status);
        }

        $paginator = $query->paginate($perPage);

        return $this->successResponse([
            'items' => StockReplenishmentResource::collection($paginator->items()),
            'meta'  => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ], 'Daftar permintaan pengisian stok');
    }

    public function pendingCount(): JsonResponse
    {
        $count = StockReplenishmentRequest::where('status', StockReplenishmentRequest::STATUS_PENDING)->count();

        return $this->successResponse(['count' => $count], 'Jumlah permintaan pending');
    }

    public function store(StoreStockReplenishmentRequest $request): JsonResponse
    {
        $req = $this->service->create($request->validated());

        return $this->successResponse(
            new StockReplenishmentResource($req->load(['items', 'fromLocation', 'toLocation', 'transferOut'])),
            'Permintaan pengisian stok berhasil dibuat',
            201,
        );
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
            return $this->errorResponse($e->getMessage(), 422);
        }

        return $this->successResponse(
            new StockReplenishmentResource($req->load(['items', 'fromLocation', 'toLocation', 'assignee', 'transferOut'])),
            'Permintaan disetujui',
        );
    }

    public function reject(string $id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $req = $this->service->reject($id, $validated['reason'] ?? null);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        return $this->successResponse(
            new StockReplenishmentResource($req->load(['items'])),
            'Permintaan ditolak',
        );
    }
}
