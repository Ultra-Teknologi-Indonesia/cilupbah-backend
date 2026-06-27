<?php

namespace Modules\Purchase\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Purchase\Services\PurchaseOrderService;
use Modules\Purchase\Http\Requests\StorePurchaseOrderRequest;
use Modules\Purchase\Http\Requests\ReceivePurchaseOrderRequest;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Purchase Orders', description: 'API Endpoints for Purchase Orders')]
class PurchaseOrderController extends Controller
{
    public function __construct(
        protected PurchaseOrderService $poService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 15);
        $pos = $this->poService->getAllPaginated($limit);
        return $this->successPaginatedResponse($pos, 'Daftar PO berhasil diambil');
    }

    public function receivable(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 15);
        $pos = $this->poService->getReceivable($limit);
        return $this->successPaginatedResponse($pos, 'Daftar PO yang bisa di-receive');
    }

    public function show(string $id): JsonResponse
    {
        $po = $this->poService->getById($id);
        if (!$po) {
            return $this->errorResponse('PO tidak ditemukan', 404);
        }
        return $this->successResponse($po, 'Detail PO berhasil diambil');
    }

    public function items(string $id, Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 10);
        $items = $this->poService->getPaginatedItems($id, $perPage);
        return $this->successPaginatedResponse($items, 'Daftar produk PO berhasil diambil');
    }

    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        try {
            $po = $this->poService->create($request->validated());
            return $this->successResponse($po, 'PO berhasil dibuat', 201);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function update(string $id, StorePurchaseOrderRequest $request): JsonResponse
    {
        try {
            $po = $this->poService->update($id, $request->validated());
            return $this->successResponse($po, 'PO berhasil diperbarui');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function approve(string $id): JsonResponse
    {
        try {
            $po = $this->poService->approve($id);
            return $this->successResponse($po, 'PO berhasil diapprove');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function receive(string $id, ReceivePurchaseOrderRequest $request): JsonResponse
    {
        try {
            $result = $this->poService->receive($id, $request->validated());
            return $this->successResponse($result, 'Receive berhasil, Inbound GRN dibuat');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function cancel(string $id): JsonResponse
    {
        try {
            $po = $this->poService->cancel($id);
            return $this->successResponse($po, 'PO berhasil dibatalkan');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function destroy(string $id): JsonResponse
    {
        try {
            $this->poService->delete($id);
            return $this->successResponse(null, 'PO berhasil dihapus');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function bulkDelete(\Illuminate\Http\Request $request): JsonResponse
    {
        $request->validate(['ids' => 'required|array']);
        $result = $this->poService->bulkDelete($request->ids);
        return $this->successResponse($result, "{$result['deleted']} PO berhasil dihapus, {$result['failed']} gagal.");
    }
}
