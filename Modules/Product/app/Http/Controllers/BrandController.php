<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Product\Http\Resources\BrandResource;
use Modules\Product\Services\BrandService;

class BrandController extends Controller
{
    use ApiResponse;

    protected BrandService $brandService;

    public function __construct(BrandService $brandService)
    {
        $this->brandService = $brandService;
    }

    public function index(Request $request): JsonResponse
    {
        if ($request->query('all')) {
            $brands = $this->brandService->getAllBrands();
            return $this->successResponse(BrandResource::collection($brands), 'Berhasil mengambil semua brand');
        }

        $brands = $this->brandService->getPaginatedBrands();
        return $this->successPaginatedResponse(BrandResource::collection($brands), 'Berhasil mengambil daftar brand');
    }
}
