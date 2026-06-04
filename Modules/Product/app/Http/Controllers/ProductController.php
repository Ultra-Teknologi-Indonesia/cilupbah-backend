<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\Product\Http\Requests\CreateProductRequest;
use Modules\Product\Services\ProductService;
use OpenApi\Attributes as OA;

class ProductController extends Controller
{
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
            new OA\Parameter(name: 'filter[name]', in: 'query', required: false, description: 'Cari berdasarkan nama produk dengan Fuzzy Search', schema: new OA\Schema(type: 'string')),
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
        $limit = $request->input('limit', 20);
        
        $products = \Spatie\QueryBuilder\QueryBuilder::for(\Modules\Product\Models\Product::class)
            ->with(['variants', 'media', 'category', 'brand'])
            ->allowedFilters(
                \Spatie\QueryBuilder\AllowedFilter::custom('name', new \App\Filters\FuzzyFilter()),
                \Spatie\QueryBuilder\AllowedFilter::exact('is_active')
            )
            ->allowedSorts('name', 'created_at')
            ->paginate($limit);
        
        return response()->json([
            'status' => 'success',
            'data' => $products->items(),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ]
        ]);
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
            
            return response()->json([
                'status' => 'success',
                'message' => 'Product created successfully',
                'data' => [
                    'product_id' => $productId
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Show the specified resource.
     */
    public function show($id)
    {
        return view('product::show');
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
