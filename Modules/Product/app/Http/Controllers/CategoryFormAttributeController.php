<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
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
}
