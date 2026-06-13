<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Product\Http\Resources\MasterItemResource;
use Modules\Product\Services\CatalogService;

class CatalogController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CatalogService $service,
    ) {}

    public function group(string $groupId): JsonResponse
    {
        return $this->successPaginatedResponse(
            MasterItemResource::collection($this->service->itemsByGroup($groupId)),
            'Daftar item dalam grup katalog'
        );
    }

    public function forListing(string $id): JsonResponse
    {
        $product = $this->service->forListing($id);

        if (! $product) {
            return $this->errorResponse('Produk tidak ditemukan', 404);
        }

        return $this->successResponse(new MasterItemResource($product), 'Detail item untuk listing');
    }
}
