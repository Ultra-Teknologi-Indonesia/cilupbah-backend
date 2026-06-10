<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Product\Http\Resources\ArchiveItemResource;
use Modules\Product\Models\Product;
use Modules\Product\Services\ArchiveFeedService;

class ArchiveFeedController extends Controller
{
    use ApiResponse;

    public function __construct(private ArchiveFeedService $service) {}

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
                fn (Product $product) => (new ArchiveItemResource($product))->resolve($request)
            )
        );

        return $this->successPaginatedResponse($paginator, 'Get archived products success');
    }

    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $product = $this->service->find($id);
        } catch (ModelNotFoundException) {
            return $this->errorResponse('Produk arsip tidak ditemukan', 404);
        }

        return $this->successResponse(
            (new ArchiveItemResource($product))->resolve($request),
            'Get archived product success'
        );
    }
}
