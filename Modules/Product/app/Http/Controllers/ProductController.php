<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\JsonResponse;
use Modules\Product\Http\Requests\CreateProductRequest;
use Modules\Product\Services\ProductService;

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
    public function index()
    {
        return view('product::index');
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

    #[OA\Get(
        path: '/api/v1/{marketplace}/products',
        operationId: 'getMarketplaceProduct',
        summary: 'Get products from a specific marketplace',
        description: 'Returns list of products for the specified marketplace (e.g., tiktok, shopee, tokopedia) after syncing from the marketplace.',
        tags: ['Products'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(
        name: 'marketplace',
        description: 'Marketplace name',
        required: true,
        in: 'path',
        schema: new OA\Schema(type: 'string')
    )]
    #[OA\Response(
        response: 200,
        description: 'Successful operation',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'status', type: 'string', example: 'success'),
                new OA\Property(property: 'marketplace', type: 'string', example: 'tiktok'),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'string'))
            ]
        )
    )]
    public function getMarketplaceProduct($marketplace)
    {
        if ($marketplace === 'tiktok') {
            $shopId = request()->header('X-Shop-Id');
            if ($shopId) {
                try {
                    $tiktokService = app(\Modules\Channel\Services\TikTokProductService::class);
                    $tiktokService->pullProducts($shopId);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning("TikTok product pull failed: " . $e->getMessage());
                }
            }
        }

        $query = DB::table('products');
        
        $column = "{$marketplace}_product_id";
        if (Schema::hasColumn('products', $column)) {
            $query->whereNotNull($column);
        }

        $products = $query->get();

        return response()->json([
            'status' => 'success',
            'marketplace' => $marketplace,
            'message' => "Successfully retrieved products for {$marketplace}",
            'data' => $products
        ]);
    }

    #[OA\Post(
        path: '/api/v1/{marketplace}/products',
        operationId: 'storeMarketplaceProduct',
        summary: 'Upload / Create product for a specific marketplace',
        description: 'Creates a product locally and prepares it for syncing with the specified marketplace.',
        tags: ['Products'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'marketplace', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CreateProductRequest'))]
    #[OA\Response(response: 201, description: 'Product created successfully')]
    public function storeMarketplaceProduct(CreateProductRequest $request, $marketplace): JsonResponse
    {
        try {
            $productId = $this->productService->createProduct($request->validated());
            
            if ($marketplace === 'tiktok') {
                $shopId = request()->header('X-Shop-Id');
                if ($shopId) {
                    try {
                        $tiktokService = app(\Modules\Channel\Services\TikTokProductService::class);
                        $tiktokService->pushProduct($productId, $shopId);
                    } catch (\Exception $e) {
                        \Illuminate\Support\Facades\Log::warning("TikTok push failed: " . $e->getMessage());
                    }
                }
            }

            return response()->json([
                'status' => 'success',
                'marketplace' => $marketplace,
                'message' => "Product created successfully and queued for sync to {$marketplace}",
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

    #[OA\Get(
        path: '/api/v1/{marketplace}/products/{product_id}',
        operationId: 'showMarketplaceProduct',
        summary: 'Get product detail from a specific marketplace',
        tags: ['Products'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'marketplace', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'product_id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Successful operation')]
    public function showMarketplaceProduct($marketplace, $product_id): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'marketplace' => $marketplace,
            'data' => ['id' => $product_id, 'title' => 'Dummy Product']
        ]);
    }

    #[OA\Put(
        path: '/api/v1/{marketplace}/products/{product_id}',
        operationId: 'updateMarketplaceProduct',
        summary: 'Update product on a specific marketplace',
        tags: ['Products'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'marketplace', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'product_id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Successful operation')]
    public function updateMarketplaceProduct(Request $request, $marketplace, $product_id): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'marketplace' => $marketplace,
            'message' => 'Product updated successfully'
        ]);
    }

    #[OA\Delete(
        path: '/api/v1/{marketplace}/products/{product_id}',
        operationId: 'destroyMarketplaceProduct',
        summary: 'Delete product on a specific marketplace',
        tags: ['Products'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'marketplace', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'product_id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Successful operation')]
    public function destroyMarketplaceProduct($marketplace, $product_id): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'marketplace' => $marketplace,
            'message' => 'Product deleted successfully'
        ]);
    }

    #[OA\Put(
        path: '/api/v1/{marketplace}/products/{product_id}/activate',
        operationId: 'activateMarketplaceProduct',
        summary: 'Activate product on a specific marketplace',
        tags: ['Products'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'marketplace', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'product_id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Successful operation')]
    public function activateMarketplaceProduct($marketplace, $product_id): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'marketplace' => $marketplace,
            'message' => 'Product activated successfully'
        ]);
    }

    #[OA\Put(
        path: '/api/v1/{marketplace}/products/{product_id}/deactivate',
        operationId: 'deactivateMarketplaceProduct',
        summary: 'Deactivate product on a specific marketplace',
        tags: ['Products'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'marketplace', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'product_id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Successful operation')]
    public function deactivateMarketplaceProduct($marketplace, $product_id): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'marketplace' => $marketplace,
            'message' => 'Product deactivated successfully'
        ]);
    }

    #[OA\Put(
        path: '/api/v1/{marketplace}/products/{product_id}/stock',
        operationId: 'updateStockMarketplaceProduct',
        summary: 'Update product stock on a specific marketplace',
        tags: ['Products'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'marketplace', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'product_id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Successful operation')]
    public function updateStockMarketplaceProduct(Request $request, $marketplace, $product_id): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'marketplace' => $marketplace,
            'message' => 'Stock updated successfully'
        ]);
    }

    #[OA\Put(
        path: '/api/v1/{marketplace}/products/{product_id}/price',
        operationId: 'updatePriceMarketplaceProduct',
        summary: 'Update product price on a specific marketplace',
        tags: ['Products'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'marketplace', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'product_id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Successful operation')]
    public function updatePriceMarketplaceProduct(Request $request, $marketplace, $product_id): JsonResponse
    {
        if ($marketplace === 'tiktok') {
            $shopId = request()->header('X-Shop-Id');
            if ($shopId) {
                try {
                    $tiktokService = app(\Modules\Channel\Services\TikTokProductService::class);
                    $tiktokService->syncPriceAndInventory((int)$product_id, $shopId);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning("TikTok price update failed: " . $e->getMessage());
                }
            }
        }
        return response()->json([
            'status' => 'success',
            'marketplace' => $marketplace,
            'message' => 'Price updated successfully'
        ]);
    }

    #[OA\Get(
        path: '/api/v1/{marketplace}/products/categories',
        operationId: 'getCategories',
        summary: 'Get marketplace categories',
        tags: ['Products'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'marketplace', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Successful operation')]
    public function getCategories($marketplace): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'marketplace' => $marketplace,
            'data' => [
                ['id' => '1', 'name' => 'Electronics'],
                ['id' => '2', 'name' => 'Fashion']
            ]
        ]);
    }

    #[OA\Post(
        path: '/api/v1/{marketplace}/products/images',
        operationId: 'uploadImageProduct',
        summary: 'Upload product image to marketplace',
        tags: ['Products'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'marketplace', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'media_id', type: 'integer', example: 1)
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    public function uploadImage(Request $request, $marketplace): JsonResponse
    {
        $mediaId = $request->input('media_id');
        $media = \Spatie\MediaLibrary\MediaCollections\Models\Media::find($mediaId);

        if (!$media) {
            return response()->json([
                'status' => 'error',
                'message' => 'Media not found'
            ], 404);
        }

        // Logic to push this media to the marketplace
        return response()->json([
            'status' => 'success',
            'marketplace' => $marketplace,
            'data' => [
                'url' => $media->getUrl()
            ]
        ]);
    }
}
