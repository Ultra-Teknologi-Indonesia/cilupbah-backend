<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Product\Models\Attribute;
use Modules\Product\Models\CategoryAttribute;
use Modules\Product\Repositories\CategoryAttributeRepository;

class CategoryFormAttributeController extends Controller
{
    use ApiResponse;

    public function __construct(private CategoryAttributeRepository $repository) {}

    public function show($categoryId)
    {
        $categoryId = (int) $categoryId;

        if (! $this->repository->exists($categoryId)) {
            return $this->errorResponse('Kategori tidak ditemukan', 404);
        }

        if (! $this->repository->isLeaf($categoryId)) {
            return $this->errorResponse('Pilih kategori paling spesifik (kategori tanpa sub-kategori).', 422);
        }

        return $this->successResponse(
            $this->repository->formAttributes($categoryId),
            'Atribut form kategori berhasil diambil.'
        );
    }

    public function store(Request $request, int $categoryId): JsonResponse
    {
        if (! $this->repository->exists($categoryId)) {
            return $this->errorResponse('Kategori tidak ditemukan', 404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:sales,spec',
        ]);

        $result = DB::transaction(function () use ($validated, $categoryId) {
            $attribute = Attribute::create([
                'name' => $validated['name'],
                'type' => $validated['type'],
            ]);

            CategoryAttribute::create([
                'category_id' => $categoryId,
                'attribute_id' => $attribute->id,
                'is_required' => true,
            ]);

            return $attribute;
        });

        return $this->successResponse($result, 'Atribut berhasil ditambahkan ke kategori', 201);
    }

    public function destroy(int $categoryId, int $attributeId): JsonResponse
    {
        if (! $this->repository->exists($categoryId)) {
            return $this->errorResponse('Kategori tidak ditemukan', 404);
        }

        $link = CategoryAttribute::where('category_id', $categoryId)
            ->where('attribute_id', $attributeId)
            ->first();

        if (! $link) {
            return $this->errorResponse('Atribut tidak ditemukan di kategori ini', 404);
        }

        DB::transaction(function () use ($link, $attributeId) {
            $link->delete();

            $stillUsed = CategoryAttribute::where('attribute_id', $attributeId)->exists();
            if (! $stillUsed) {
                Attribute::where('id', $attributeId)->delete();
            }
        });

        return $this->successResponse(null, 'Atribut berhasil dihapus dari kategori');
    }
}
