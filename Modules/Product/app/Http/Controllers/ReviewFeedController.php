<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Product\Http\Resources\ReviewItemResource;
use Modules\Product\Models\Product;
use Modules\Product\Services\ReviewFeedService;

class ReviewFeedController extends Controller
{
    use ApiResponse;

    public function __construct(private ReviewFeedService $service) {}

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
                fn (Product $product) => (new ReviewItemResource($product))->resolve($request)
            )
        );

        return $this->successPaginatedResponse($paginator, 'Get review items success');
    }
}
