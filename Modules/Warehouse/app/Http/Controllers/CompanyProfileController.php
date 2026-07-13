<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Warehouse\Http\Requests\SaveCompanyProfileRequest;
use Modules\Warehouse\Http\Resources\CompanyProfileResource;
use Modules\Warehouse\Services\CompanyProfileService;

class CompanyProfileController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CompanyProfileService $service,
    ) {}

    public function index(): JsonResponse
    {
        return $this->successResponse(
            new CompanyProfileResource($this->service->get()),
            'Identitas perusahaan berhasil diambil'
        );
    }

    public function store(SaveCompanyProfileRequest $request): JsonResponse
    {
        return $this->successResponse(
            new CompanyProfileResource($this->service->save($request->validated())),
            'Identitas perusahaan berhasil disimpan'
        );
    }
}
