<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Product\Http\Resources\ChannelProductListingResource;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Services\ChannelProductListingService;

class ChannelProductListingController extends Controller
{
    use ApiResponse;

    public function __construct(private ChannelProductListingService $service) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => 'nullable|string',
            'per_page' => 'nullable|integer|min:1|max:500',
            'page' => 'nullable|integer|min:1',
        ]);

        $paginator = $this->service->paginate();

        $paginator->setCollection(
            $paginator->getCollection()->map(
                fn (ProductChannelMapping $mapping) => (new ChannelProductListingResource($mapping))->resolve($request)
            )
        );

        return $this->successPaginatedResponse($paginator, 'Get channel products success');
    }

    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $mapping = $this->service->find($id);
        } catch (ModelNotFoundException) {
            return $this->errorResponse('Listing produk channel tidak ditemukan', 404);
        }

        return $this->successResponse(
            (new ChannelProductListingResource($mapping))->resolve($request),
            'Get channel product detail success'
        );
    }
}
