<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Product\Http\Resources\CategoryResource;
use Modules\Product\Services\CategoryService;

class CategoryController extends Controller
{
    use ApiResponse;

    protected CategoryService $categoryService;

    public function __construct(CategoryService $categoryService)
    {
        $this->categoryService = $categoryService;
    }

    public function index(Request $request): JsonResponse
    {
        if ($request->query('all')) {
            $categories = $this->categoryService->getAllCategories();
            return $this->successResponse(CategoryResource::collection($categories), 'Berhasil mengambil semua kategori');
        }

        $categories = $this->categoryService->getPaginatedCategories();
        return $this->successPaginatedResponse(CategoryResource::collection($categories), 'Berhasil mengambil daftar kategori');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'parent_id' => 'nullable|bail|integer|exists:categories,id',
            'is_active' => 'nullable|boolean',
            'is_leaf' => 'nullable|boolean',
        ]);

        try {
            $category = $this->categoryService->createCategory($validated);
            return $this->successResponse(new CategoryResource($category), 'Kategori berhasil dibuat', 201);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function show(int $id): JsonResponse
    {
        try {
            $category = $this->categoryService->getCategoryById($id);
            return $this->successResponse(new CategoryResource($category), 'Berhasil mengambil detail kategori');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 404);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'parent_id' => 'nullable|bail|integer|exists:categories,id',
            'is_active' => 'nullable|boolean',
        ]);

        try {
            $category = $this->categoryService->updateCategory($id, $validated);
            return $this->successResponse(new CategoryResource($category), 'Kategori berhasil diperbarui');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $this->categoryService->deleteCategory($id);
            return $this->successResponse(null, 'Kategori berhasil dihapus');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    public function mapChannel(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'channel_category_ids' => 'required|array',
            'channel_category_ids.*' => 'required|uuid|exists:channel_categories,id',
        ]);

        try {
            $category = $this->categoryService->mapToChannel($id, $validated['channel_category_ids']);
            return $this->successResponse(new CategoryResource($category), 'Berhasil memetakan kategori ke channel');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function channelMap(int $id): JsonResponse
    {
        try {
            $category = $this->categoryService->getChannelMapping($id);
            return $this->successResponse(new CategoryResource($category), 'Berhasil mengambil pemetaan kategori ke channel');
        } catch (\Throwable $e) {
            return $this->errorResponse('Kategori tidak ditemukan', 404);
        }
    }

    public function systemCategories(Request $request): JsonResponse
    {
        $categories = $this->categoryService->getSystemCategories();
        return $this->successResponse(CategoryResource::collection($categories), 'Berhasil mengambil daftar kategori sistem');
    }

    public function enableCategories(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:categories,id',
        ]);

        try {
            $count = $this->categoryService->enableSystemCategories($validated['ids']);
            return $this->successResponse(['enabled_count' => $count], "Berhasil mengaktifkan {$count} kategori");
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    public function disableCategories(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:categories,id',
        ]);

        try {
            $count = $this->categoryService->disableSystemCategories($validated['ids']);
            return $this->successResponse(['disabled_count' => $count], "Berhasil menonaktifkan {$count} kategori");
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    public function mappingList(Request $request): JsonResponse
    {
        $mappings = $this->categoryService->getMappingList();
        return $this->successPaginatedResponse($mappings, 'Berhasil mengambil daftar mapping kategori');
    }
}
