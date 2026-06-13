<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Product\Http\Requests\StorePromotionRequest;
use Modules\Product\Http\Resources\PromotionResource;
use Modules\Product\Services\PromotionService;

class PromotionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PromotionService $service,
    ) {
    }

    public function index(): JsonResponse
    {
        return $this->successPaginatedResponse(
            PromotionResource::collection($this->service->list()),
            'Get promotions success'
        );
    }

    public function store(StorePromotionRequest $request): JsonResponse
    {
        $promotion = $this->service->create($request->validated());

        return $this->successResponse(new PromotionResource($promotion), 'Promosi berhasil dibuat', 201);
    }

    public function show(string $id): JsonResponse
    {
        $promotion = $this->service->find($id);
        if (! $promotion) {
            return $this->errorResponse('Promosi tidak ditemukan', 404);
        }

        return $this->successResponse(new PromotionResource($promotion), 'Get promotion detail success');
    }

    public function destroy(string $id): JsonResponse
    {
        $promotion = $this->service->find($id);
        if (! $promotion) {
            return $this->errorResponse('Promosi tidak ditemukan', 404);
        }

        $this->service->delete($promotion);

        return $this->successResponse(null, 'Promosi berhasil dihapus');
    }
}
