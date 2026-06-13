<?php

namespace Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Finance\Http\Requests\SaveAccountMappingRequest;
use Modules\Finance\Http\Resources\AccountMappingResource;
use Modules\Finance\Services\AccountMappingService;

class AccountMappingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AccountMappingService $service,
    ) {}

    public function index(): JsonResponse
    {
        return $this->successResponse(
            AccountMappingResource::collection($this->service->list()),
            'Pemetaan akun berhasil diambil'
        );
    }

    public function store(SaveAccountMappingRequest $request): JsonResponse
    {
        $result = $this->service->save($request->validated()['mappings']);

        return $this->successResponse(
            AccountMappingResource::collection($result),
            'Pemetaan akun berhasil disimpan'
        );
    }
}
