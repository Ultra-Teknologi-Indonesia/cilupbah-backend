<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Product\Http\Resources\AttributeResource;
use Modules\Product\Services\CategoryFormAttributeService;

class CategoryFormAttributeController extends Controller
{
    use ApiResponse;

    public function __construct(
        private CategoryFormAttributeService $service,
    ) {}

    public function show($categoryId)
    {
        $categoryId = (int) $categoryId;

        if (! $this->service->categoryExists($categoryId)) {
            return $this->errorResponse('Kategori tidak ditemukan', 404);
        }

        if (! $this->service->categoryIsLeaf($categoryId)) {
            return $this->errorResponse('Pilih kategori paling spesifik (kategori tanpa sub-kategori).', 422);
        }

        return $this->successResponse(
            $this->service->formAttributes($categoryId),
            'Atribut form kategori berhasil diambil.'
        );
    }

    public function store(Request $request, int $categoryId): JsonResponse
    {
        if (! $this->service->categoryExists($categoryId)) {
            return $this->errorResponse('Kategori tidak ditemukan', 404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:sales,spec',
        ]);

        $attribute = $this->service->createAttribute($categoryId, $validated);

        return $this->successResponse(new AttributeResource($attribute), 'Atribut berhasil ditambahkan ke kategori', 201);
    }

    public function destroy(int $categoryId, int $attributeId): JsonResponse
    {
        if (! $this->service->categoryExists($categoryId)) {
            return $this->errorResponse('Kategori tidak ditemukan', 404);
        }

        if (! $this->service->deleteAttribute($categoryId, $attributeId)) {
            return $this->errorResponse('Atribut tidak ditemukan di kategori ini', 404);
        }

        return $this->successResponse(null, 'Atribut berhasil dihapus dari kategori');
    }
}
