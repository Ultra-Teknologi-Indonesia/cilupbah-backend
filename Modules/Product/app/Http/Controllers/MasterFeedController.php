<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Product\Http\Resources\MasterItemResource;
use Modules\Product\Models\Product;
use Modules\Product\Services\MasterFeedService;

class MasterFeedController extends Controller
{
    use ApiResponse;

    public function __construct(private MasterFeedService $service) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => 'nullable|string',
            'per_page' => 'nullable|integer|min:1|max:500',
            'page' => 'nullable|integer|min:1',
            'status' => ['nullable', Rule::in(Product::STATUSES)],
            'updated_since' => 'nullable|date',
        ]);

        $paginator = $this->service->paginate(
            $request->query('status'),
            $request->query('updated_since'),
        );

        $paginator->through(
            fn (Product $product) => (new MasterItemResource($product))->resolve($request)
        );

        return $this->successPaginatedResponse($paginator, 'Get master items success');
    }

    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $product = $this->service->find($id);
        } catch (ModelNotFoundException) {
            return $this->errorResponse('Master item tidak ditemukan', 404);
        }

        return $this->successResponse(
            (new MasterItemResource($product))->resolve($request),
            'Get master item success'
        );
    }
}
