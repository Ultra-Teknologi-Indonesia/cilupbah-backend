<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Warehouse\Http\Requests\SaveWarehouseSettingRequest;
use Modules\Warehouse\Http\Resources\WarehouseSettingResource;
use Modules\Warehouse\Services\WarehouseSettingService;

class WarehouseSettingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly WarehouseSettingService $service,
    ) {}

    public function index(): JsonResponse
    {
        return $this->successResponse(
            new WarehouseSettingResource($this->service->get()),
            'Pengaturan gudang berhasil diambil'
        );
    }

    public function store(SaveWarehouseSettingRequest $request): JsonResponse
    {
        return $this->successResponse(
            new WarehouseSettingResource($this->service->save($request->validated())),
            'Pengaturan gudang berhasil disimpan'
        );
    }
}
