<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\Product\Http\Requests\CreateProductRequest;
use Modules\Product\Http\Resources\ProductResource;
use Modules\Product\Models\Product;
use Modules\Product\Services\ProductService;
use OpenApi\Attributes as OA;
use App\Traits\ApiResponse;

class ProductController extends Controller
{
    use ApiResponse;
    protected ProductService $productService;

    public function __construct(ProductService $productService)
    {
        $this->productService = $productService;
    }

    /**
     * Display a listing of the resource.
     */
    #[OA\Get(
        path: '/api/v1/products',
        summary: 'List all products',
        tags: ['Products'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', in: 'query', required: false, description: 'Number of items per page', schema: new OA\Schema(type: 'integer', default: 20)),
            new OA\Parameter(name: 'filter[is_active]', in: 'query', required: false, description: 'Filter aktif/tidak aktif (true/false/1/0)', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Filter status lifecycle: download | in_review | master | archived', schema: new OA\Schema(type: 'string', enum: ['download', 'in_review', 'master', 'archived'])),
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Cari berdasarkan nama produk dengan PostgreSQL Full-Text Search', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, description: 'Sort field (e.g. name, -created_at)', schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Success',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'success'),
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'meta', type: 'object')
                    ]
                )
            )
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $limit = $request->input('limit', 10);

        $status = $request->query('status');
        if ($status !== null && !in_array($status, Product::STATUSES, true)) {
            return $this->errorResponse('Status tidak valid', 422, [
                'status' => ['Nilai harus salah satu dari: ' . implode(', ', Product::STATUSES)],
            ]);
        }

        $products = \Spatie\QueryBuilder\QueryBuilder::for(Product::class)
            ->with(['variants', 'media', 'category', 'brand', 'channelMappings.channelShop.channel'])
            ->allowedSearch('name')
            ->allowedFilters(
                \Spatie\QueryBuilder\AllowedFilter::exact('is_active')
            )
            ->allowedSorts('name', 'created_at')
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->paginate($limit);

        return $this->successPaginatedResponse(ProductResource::collection($products), 'Get products success');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('product::create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateProductRequest $request): JsonResponse
    {
        try {
            $productId = $this->productService->createProduct($request->validated());
            return $this->successResponse([
                'product_id' => $productId
            ], 'Product created successfully', 201);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to create product', 500, ['error' => $e->getMessage()]);
        }
    }

    /**
     * Show the specified resource.
     */
    #[OA\Get(
        path: '/api/v1/products/{id}',
        summary: 'Get product detail',
        tags: ['Products'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Get product detail success'),
            new OA\Response(response: 404, description: 'Product not found')
        ]
    )]
    public function show($id): JsonResponse
    {
        // Guard: id non-UUID akan memicu error cast UUID di Postgres, bukan "not found".
        $normalizedId = str_replace('-', '', (string) $id);
        if (strlen($normalizedId) !== 32 || !ctype_xdigit($normalizedId)) {
            return $this->errorResponse('Produk tidak ditemukan', 404);
        }

        $product = Product::with([
            'variants',
            'media',
            'category',
            'brand',
            'channelMappings.channelShop.channel',
            'archivedBy',
        ])->find($id);

        if (!$product) {
            return $this->errorResponse('Produk tidak ditemukan', 404);
        }

        return $this->successResponse(new ProductResource($product), 'Get product detail success');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        return view('product::edit');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id) {}

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id) {}
}
