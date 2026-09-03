<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Product\Services\ProductPickerFeedService;
use OpenApi\Attributes as OA;

class ProductPickerFeedController extends Controller
{
    use ApiResponse;

    public function __construct(private ProductPickerFeedService $service) {}

    #[OA\Get(
        path: '/api/v1/products/picker',
        summary: 'Get products and variants for product picker lookup',
        security: [['bearerAuth' => []]],
        tags: ['Products'],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'exclude_bundles', in: 'query', required: false, schema: new OA\Schema(type: 'boolean', default: false)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Get picker products success'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => 'nullable|string',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
            'exclude_bundles' => 'nullable|boolean',
        ]);

        $paginator = $this->service->paginate(
            $request->query('search'),
            (int) $request->query('per_page', 20),
            (int) $request->query('page', 1),
            $request->boolean('exclude_bundles'),
        );

        return $this->successPaginatedResponse($paginator, 'Get picker products success');
    }
}
