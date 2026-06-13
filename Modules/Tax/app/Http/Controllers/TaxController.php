<?php

namespace Modules\Tax\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Tax\Http\Requests\StoreTaxRequest;
use Modules\Tax\Http\Resources\TaxResource;
use Modules\Tax\Services\TaxService;

class TaxController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TaxService $service,
    ) {
    }

    public function index(): JsonResponse
    {
        return $this->successPaginatedResponse(
            TaxResource::collection($this->service->list()),
            'Daftar pajak berhasil diambil'
        );
    }

    public function store(StoreTaxRequest $request): JsonResponse
    {
        $tax = $this->service->create($request->validated());

        return $this->successResponse(new TaxResource($tax), 'Pajak berhasil dibuat', 201);
    }

    public function show(int $id): JsonResponse
    {
        $tax = $this->service->find($id);
        if (! $tax) {
            return $this->errorResponse('Pajak tidak ditemukan', 404);
        }

        return $this->successResponse(new TaxResource($tax), 'Detail pajak berhasil diambil');
    }

    public function update(StoreTaxRequest $request, int $id): JsonResponse
    {
        $tax = $this->service->find($id);
        if (! $tax) {
            return $this->errorResponse('Pajak tidak ditemukan', 404);
        }

        $tax = $this->service->update($tax, $request->validated());

        return $this->successResponse(new TaxResource($tax), 'Pajak berhasil diperbarui');
    }

    public function destroy(int $id): JsonResponse
    {
        $tax = $this->service->find($id);
        if (! $tax) {
            return $this->errorResponse('Pajak tidak ditemukan', 404);
        }

        $this->service->delete($tax);

        return $this->successResponse(null, 'Pajak berhasil dihapus');
    }
}
