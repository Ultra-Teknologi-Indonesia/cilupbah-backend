<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Http\Resources\ProductUploadListingResource;
use Modules\Product\Services\ProductUploadListingService;

class ProductUploadListingController extends Controller
{
    use ApiResponse;

    public function __construct(private ProductUploadListingService $service) {}

    public function index(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'is_uploaded' => 'nullable|in:true,false,1,0',
            'search' => 'nullable|string',
            'per_page' => 'nullable|integer|min:1|max:500',
            'page' => 'nullable|integer|min:1',
        ]);

        try {
            $paginator = $this->service->listDestinations($id, $request->boolean('is_uploaded'));
        } catch (ModelNotFoundException) {
            return $this->errorResponse('Produk tidak ditemukan', 404);
        }

        $paginator->setCollection(
            $paginator->getCollection()->map(
                fn (ChannelShop $shop) => (new ProductUploadListingResource($shop))->resolve($request)
            )
        );

        return $this->successPaginatedResponse($paginator, 'Get product upload listing success');
    }

    public function match(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'store_ids' => 'required|array|min:1',
            'store_ids.*' => 'uuid',
        ]);

        try {
            $rows = $this->service->match($id, $validated['store_ids']);
        } catch (ModelNotFoundException) {
            return $this->errorResponse('Produk tidak ditemukan', 404);
        }

        return $this->successResponse($rows, 'Cek kecocokan data master selesai');
    }
}
