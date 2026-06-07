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

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        try {
            $brand = $this->brandService->createBrand($validated);
            return $this->successResponse(new BrandResource($brand), 'Brand berhasil dibuat', 201);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function show(int $id): JsonResponse
    {
        try {
            $brand = $this->brandService->getBrandById($id);
            return $this->successResponse(new BrandResource($brand), 'Berhasil mengambil detail brand');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 404);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        try {
            $brand = $this->brandService->updateBrand($id, $validated);
            return $this->successResponse(new BrandResource($brand), 'Brand berhasil diperbarui');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $this->brandService->deleteBrand($id);
            return $this->successResponse(null, 'Brand berhasil dihapus');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }
}
