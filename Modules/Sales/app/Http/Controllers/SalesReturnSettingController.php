<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Sales\Http\Requests\SaveSalesReturnSettingRequest;
use Modules\Sales\Http\Resources\SalesReturnSettingResource;
use Modules\Sales\Services\SalesReturnSettingService;

class SalesReturnSettingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly SalesReturnSettingService $service,
    ) {}

    public function index(): JsonResponse
    {
        return $this->successResponse(
            new SalesReturnSettingResource($this->service->get()),
            'Pengaturan retur penjualan berhasil diambil'
        );
    }

    public function store(SaveSalesReturnSettingRequest $request): JsonResponse
    {
        return $this->successResponse(
            new SalesReturnSettingResource($this->service->save($request->validated())),
            'Pengaturan retur penjualan berhasil disimpan'
        );
    }
}
