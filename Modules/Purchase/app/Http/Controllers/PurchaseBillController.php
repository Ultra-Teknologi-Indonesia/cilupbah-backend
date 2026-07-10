<?php

namespace Modules\Purchase\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Purchase\Services\PurchaseBillService;
use Modules\Purchase\Http\Requests\StorePurchaseBillRequest;
use Modules\Purchase\Http\Resources\PurchaseBillResource;

class PurchaseBillController extends Controller
{
    public function __construct(
        protected PurchaseBillService $billService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $limit = $request->query('per_page', 20);
        return $this->successPaginatedResponse($this->billService->getAllPaginated($limit), 'Daftar tagihan berhasil diambil');
    }

    public function store(StorePurchaseBillRequest $request): JsonResponse
    {
        try {
            $bill = $this->billService->create($request->validated());
            return $this->successResponse($bill, 'Tagihan berhasil dibuat', 201);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function show(string $id): JsonResponse
    {
        $bill = $this->billService->getById($id);
        if (!$bill) {
            return $this->errorResponse('Tagihan tidak ditemukan', 404);
        }
        return $this->successResponse(new PurchaseBillResource($bill), 'Detail tagihan berhasil diambil');
    }

    public function update(string $id, StorePurchaseBillRequest $request): JsonResponse
    {
        try {
            $bill = $this->billService->update($id, $request->validated());
            return $this->successResponse($bill, 'Tagihan berhasil diperbarui');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function destroy(Request $request): JsonResponse
    {
        try {
            $this->billService->delete($request->input('id'));
            return $this->successResponse(null, 'Tagihan berhasil dihapus');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function unpaid(Request $request): JsonResponse
    {
        $limit = $request->query('per_page', 20);
        return $this->successPaginatedResponse($this->billService->getUnpaid($limit), 'Daftar tagihan belum lunas');
    }

    public function overdue(Request $request): JsonResponse
    {
        $limit = $request->query('per_page', 20);
        return $this->successPaginatedResponse($this->billService->getOverdue($limit), 'Daftar tagihan jatuh tempo');
    }

    public function forReturn(Request $request): JsonResponse
    {
        $limit = $request->query('per_page', 20);
        return $this->successPaginatedResponse($this->billService->getForReturn($limit), 'Daftar tagihan untuk return');
    }
}
