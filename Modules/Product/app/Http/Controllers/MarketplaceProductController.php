<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Modules\Product\Models\Product;
use Modules\Product\Services\ProductService;
use Modules\Product\Http\Requests\CreateProductRequest;

class MarketplaceProductController extends Controller
{
    protected ProductService $productService;

    public function __construct(ProductService $productService)
    {
        $this->productService = $productService;
    }

    #[OA\Get(
        path: "/api/v1/{marketplace}/products",
        summary: "List products",
        description: "List produk (paginated) untuk marketplace tertentu.",
        tags: ["Marketplace Products"]
    )]
    #[OA\Parameter(name: "marketplace", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\Parameter(name: "page", in: "query", required: false, schema: new OA\Schema(type: "integer"))]
    #[OA\Parameter(name: "limit", in: "query", required: false, schema: new OA\Schema(type: "integer"))]
    #[OA\Parameter(name: "filter[is_active]", in: "query", required: false, description: "Filter aktif/tidak aktif (true/false/1/0)", schema: new OA\Schema(type: "boolean"))]
    #[OA\Parameter(name: "filter[name]", in: "query", required: false, description: "Cari berdasarkan nama produk", schema: new OA\Schema(type: "string"))]
    #[OA\Parameter(name: "sort", in: "query", required: false, description: "Sort field (e.g. name, -created_at, sell_price)", schema: new OA\Schema(type: "string"))]
    #[OA\Response(response: 200, description: "Get products success")]
    public function index(Request $request, string $marketplace): JsonResponse
    {
        $limit = $request->query('limit', 10);
        
        $products = \Spatie\QueryBuilder\QueryBuilder::for(Product::class)
            ->allowedFilters(
                \Spatie\QueryBuilder\AllowedFilter::custom('name', new \App\Filters\FuzzyFilter()),
                \Spatie\QueryBuilder\AllowedFilter::exact('is_active')
            )
            ->allowedSorts('name', 'created_at')
            ->paginate($limit);

        $meta = [
            'current_page' => $products->currentPage(),
            'last_page' => $products->lastPage(),
            'per_page' => $products->perPage(),
            'total' => $products->total(),
        ];

        return response()->json([
            'status' => 'success',
            'marketplace' => $marketplace,
            'message' => 'Get products success',
            'data' => $products->items(),
            'meta' => $meta
        ]);
    }

    #[OA\Get(
        path: "/api/v1/{marketplace}/products/{id}",
        summary: "Get product detail",
        description: "Detail satu produk dari marketplace tertentu.",
        tags: ["Marketplace Products"]
    )]
    #[OA\Parameter(name: "marketplace", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\Response(response: 200, description: "Get product detail success")]
    public function show(string $marketplace, string $id): JsonResponse
    {
        $product = Product::findOrFail($id);

        return response()->json([
            'status' => 'success',
            'marketplace' => $marketplace,
            'message' => 'Get product detail success',
            'data' => $product
        ]);
    }

    #[OA\Post(
        path: "/api/v1/{marketplace}/products",
        summary: "Create product",
        description: "Buat produk baru secara lokal dan push ke marketplace. Body payload harus menyertakan shop_id dan struktur lengkap (variants, media, dll).",
        tags: ["Marketplace Products"]
    )]
    #[OA\Parameter(name: "marketplace", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\RequestBody(
        required: true, 
        content: new OA\JsonContent(
            type: "object",
            example: [
                "shop_id" => "7494685794425930858",
                "brand_id" => 1,
                "category_id" => 1,
                "name" => "Sepatu Bola Nike Mercurial Superfly",
                "sku" => "SHOE-FB-NIKE-001",
                "description" => "Sepatu bola premium dengan teknologi flyknit yang ringan dan nyaman.",
                "search_keyword" => "sepatu bola, nike mercurial",
                "order_type" => "REGULER",
                "indent_days" => 0,
                "weight" => 0.8,
                "length" => 30,
                "width" => 15,
                "height" => 12,
                "condition" => "NEW",
                "is_cod_allowed" => true,
                "danger_level" => 0,
                "is_draft" => false,
                "showcase_id" => null,
                "is_active" => true,
                "specifications" => [
                    [
                        "attribute_id" => 1,
                        "attribute_option_id" => null,
                        "text_value" => "Sintetis Premium"
                    ]
                ],
                "media" => [
                    [
                        "url" => "https://example.com/sepatu-bola.jpg",
                        "media_type" => "image",
                        "is_primary" => true,
                        "sort_order" => 1
                    ]
                ],
                "variation_types" => [
                    [
                        "attribute_id" => 1,
                        "sort_order" => 1
                    ],
                    [
                        "attribute_id" => 2,
                        "sort_order" => 2
                    ]
                ],
                "variants" => [
                    [
                        "sku" => "SHOE-FB-NIKE-001-RED-42",
                        "buy_price" => 1200000,
                        "sell_price" => 1500000,
                        "weight" => 0.8,
                        "length" => 30,
                        "width" => 15,
                        "height" => 12,
                        "is_serial_batch" => false,
                        "is_active" => true,
                        "options" => [
                            [
                                "attribute_id" => 1,
                                "value" => "Red"
                            ],
                            [
                                "attribute_id" => 2,
                                "value" => "42"
                            ]
                        ],
                        "media" => [
                            [
                                "url" => "https://example.com/sepatu-bola-red.jpg",
                                "media_type" => "image",
                                "is_primary" => true,
                                "sort_order" => 1
                            ]
                        ],
                        "wholesale_prices" => [
                            [
                                "min_qty" => 5,
                                "price" => 1400000,
                                "customer_type" => "B2B"
                            ]
                        ]
                    ]
                ]
            ]
        )
    )]
    #[OA\Response(response: 201, description: "Product created and pushed to marketplace")]
    public function store(CreateProductRequest $request, string $marketplace): JsonResponse
    {
        $shopId = $request->input('shop_id');
        if (!$shopId) {
            return response()->json([
                'status' => 'error',
                'message' => 'shop_id is required'
            ], 400);
        }

        try {
            // 1. Simpan ke database lokal menggunakan ProductService (beserta variannya)
            $productId = $this->productService->createProduct($request->validated());

            $res = null;

            // 2. Push ke Marketplace yang dituju
            if ($marketplace === 'tiktok') {
                $tiktokService = app(\Modules\Channel\Services\TikTokProductService::class);
                $res = $tiktokService->pushProduct($productId, $shopId);
            }

            return response()->json([
                'status' => 'success',
                'marketplace' => $marketplace,
                'message' => 'Product created and pushed to marketplace',
                'data' => [
                    'product_id' => $productId,
                    'marketplace_response' => $res
                ]
            ], 201);
            
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'marketplace' => $marketplace,
                'message' => 'Failed to create and push product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    #[OA\Put(
        path: "/api/v1/{marketplace}/products/{id}",
        summary: "Update product info",
        description: "Update info produk di marketplace tertentu.",
        tags: ["Marketplace Products"]
    )]
    #[OA\Parameter(name: "marketplace", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(type: "object"))]
    #[OA\Response(response: 200, description: "Product updated successfully")]
    public function update(Request $request, string $marketplace, string $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $product->update($data);

        // Untuk fitur update ke TikTok secara native (jika belum ada servis khusus di TikTokProductService)
        // Saat ini belum ada pushUpdate() di TikTokProductService, jadi kita hanya update lokal dulu
        
        return response()->json([
            'status' => 'success',
            'marketplace' => $marketplace,
            'message' => 'Product updated successfully',
            'data' => $product
        ]);
    }

    #[OA\Delete(
        path: "/api/v1/{marketplace}/products/{id}",
        summary: "Delete product",
        description: "Hapus produk di marketplace tertentu.",
        tags: ["Marketplace Products"]
    )]
    #[OA\Parameter(name: "marketplace", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\Response(response: 200, description: "Product deleted successfully")]
    public function destroy(string $marketplace, string $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $product->delete();

        return response()->json([
            'status' => 'success',
            'marketplace' => $marketplace,
            'message' => 'Product deleted successfully',
            'data' => [
                'success' => true
            ]
        ]);
    }

    #[OA\Put(
        path: "/api/v1/{marketplace}/products/{id}/activate",
        summary: "Activate product",
        description: "Aktifkan / publish produk.",
        tags: ["Marketplace Products"]
    )]
    #[OA\Parameter(name: "marketplace", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\Response(response: 200, description: "Product activated successfully")]
    public function activate(string $marketplace, string $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $product->update(['is_active' => true]);

        return response()->json([
            'status' => 'success',
            'marketplace' => $marketplace,
            'message' => 'Product activated successfully',
            'data' => $product
        ]);
    }

    #[OA\Put(
        path: "/api/v1/{marketplace}/products/{id}/deactivate",
        summary: "Deactivate product",
        description: "Nonaktifkan produk.",
        tags: ["Marketplace Products"]
    )]
    #[OA\Parameter(name: "marketplace", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\Response(response: 200, description: "Product deactivated successfully")]
    public function deactivate(string $marketplace, string $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $product->update(['is_active' => false]);

        return response()->json([
            'status' => 'success',
            'marketplace' => $marketplace,
            'message' => 'Product deactivated successfully',
            'data' => $product
        ]);
    }

    #[OA\Put(
        path: "/api/v1/{marketplace}/products/{id}/stock",
        summary: "Update stock",
        description: "Update stok SKU di marketplace. Wajib menyertakan shop_id.",
        tags: ["Marketplace Products"]
    )]
    #[OA\Parameter(name: "marketplace", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        type: "object",
        properties: [
            new OA\Property(property: "shop_id", type: "string")
        ]
    ))]
    #[OA\Response(response: 200, description: "Product stock updated successfully")]
    public function updateStock(Request $request, string $marketplace, string $id): JsonResponse
    {
        $shopId = $request->input('shop_id');
        
        if ($marketplace === 'tiktok' && $shopId) {
            $tiktokService = app(\Modules\Channel\Services\TikTokProductService::class);
            $tiktokService->syncPriceAndInventory((int)$id, $shopId);
        }

        return response()->json([
            'status' => 'success',
            'marketplace' => $marketplace,
            'message' => 'Product stock updated successfully',
            'data' => [
                'success' => true
            ]
        ]);
    }

    #[OA\Put(
        path: "/api/v1/{marketplace}/products/{id}/price",
        summary: "Update price",
        description: "Update harga SKU di marketplace. Wajib menyertakan shop_id.",
        tags: ["Marketplace Products"]
    )]
    #[OA\Parameter(name: "marketplace", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        type: "object",
        properties: [
            new OA\Property(property: "shop_id", type: "string")
        ]
    ))]
    #[OA\Response(response: 200, description: "Product price updated successfully")]
    public function updatePrice(Request $request, string $marketplace, string $id): JsonResponse
    {
        $shopId = $request->input('shop_id');
        
        if ($marketplace === 'tiktok' && $shopId) {
            $tiktokService = app(\Modules\Channel\Services\TikTokProductService::class);
            $tiktokService->syncPriceAndInventory((int)$id, $shopId);
        }

        return response()->json([
            'status' => 'success',
            'marketplace' => $marketplace,
            'message' => 'Product price updated successfully',
            'data' => [
                'success' => true
            ]
        ]);
    }

    #[OA\Get(
        path: "/api/v1/{marketplace}/products/categories",
        summary: "List categories",
        description: "Daftar kategori marketplace.",
        tags: ["Marketplace Products"]
    )]
    #[OA\Parameter(name: "marketplace", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\Parameter(name: "parent_id", in: "query", required: false, schema: new OA\Schema(type: "string"))]
    #[OA\Response(response: 200, description: "Get categories success")]
    public function categories(Request $request, string $marketplace): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'marketplace' => $marketplace,
            'message' => 'Get categories success',
            'data' => []
        ]);
    }
}
