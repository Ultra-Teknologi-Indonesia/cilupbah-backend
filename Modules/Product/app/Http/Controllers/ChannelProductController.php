<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Modules\Product\Models\Product;
use Modules\Product\Http\Resources\ProductResource;
use Modules\Product\Services\ChannelProductService;
use App\Traits\ApiResponse;

class ChannelProductController extends Controller
{
    use ApiResponse;
    protected ChannelProductService $channelProductService;

    public function __construct(ChannelProductService $channelProductService)
    {
        $this->channelProductService = $channelProductService;
    }

    #[OA\Get(
        path: "/api/v1/{channel}/products",
        summary: "List products",
        description: "List produk (paginated) untuk channel tertentu.",
        tags: ["Channel Products"]
    )]
    #[OA\Parameter(name: "channel", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\Parameter(name: "shop_id", in: "query", required: true, description: "shop_id marketplace", schema: new OA\Schema(type: "string"))]
    #[OA\Parameter(name: "page", in: "query", required: false, schema: new OA\Schema(type: "integer"))]
    #[OA\Parameter(name: "limit", in: "query", required: false, schema: new OA\Schema(type: "integer", default: 20))]
    #[OA\Parameter(name: "sync_status", in: "query", required: false, description: "Filter sync status: synced|pending|failed|syncing|deactivated", schema: new OA\Schema(type: "string"))]
    #[OA\Parameter(name: "filter[is_active]", in: "query", required: false, description: "Filter aktif/tidak aktif (true/false/1/0)", schema: new OA\Schema(type: "boolean"))]
    #[OA\Parameter(name: "filter[name]", in: "query", required: false, description: "Cari berdasarkan nama produk", schema: new OA\Schema(type: "string"))]
    #[OA\Parameter(name: "sort", in: "query", required: false, description: "Sort field (e.g. name, -created_at, sell_price)", schema: new OA\Schema(type: "string"))]
    #[OA\Response(response: 200, description: "Get products success")]
    #[OA\Response(response: 422, description: "sync_status tidak valid")]
    public function index(Request $request, string $channel): JsonResponse
    {
        $shopId     = $request->query('shop_id', '');
        $syncStatus = $request->query('sync_status');
        $limit      = (int) $request->input('limit', 20);

        $validStatuses = ['synced', 'pending', 'failed', 'syncing', 'deactivated'];
        if ($syncStatus !== null && !in_array($syncStatus, $validStatuses, true)) {
            return $this->errorResponse(
                'sync_status tidak valid. Nilai yang diizinkan: ' . implode(', ', $validStatuses),
                422
            );
        }

        $products = $this->channelProductService->getChannelProducts($shopId, $limit, $syncStatus);

        return $this->successPaginatedResponse(ProductResource::collection($products), 'Berhasil mengambil daftar produk');
    }

    #[OA\Get(
        path: "/api/v1/{channel}/products/{id}",
        summary: "Get product detail",
        description: "Detail satu produk dari channel tertentu.",
        tags: ["Channel Products"]
    )]
    #[OA\Parameter(name: "channel", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\Response(response: 200, description: "Get product detail success")]
    public function show(Request $request, string $channel, string $id): JsonResponse
    {
        $shopId = $request->query('shop_id', '');
        $product = $this->channelProductService->getProductDetail($id, $shopId);

        return $this->successResponse(new ProductResource($product), 'Berhasil mengambil detail produk');
    }

    #[OA\Post(
        path: "/api/v1/{channel}/products",
        summary: "Create product",
        description: "Buat produk baru secara lokal dan push ke channel. Body payload harus menyertakan shop_id dan struktur lengkap (variants, media, dll).",
        tags: ["Channel Products"]
    )]
    #[OA\Parameter(name: "channel", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\RequestBody(
        required: true, 
        content: new OA\JsonContent(
            type: "object",
            example: [
                "shop_id" => "7494685794425930858",
                "category_id" => 1,
                "name" => "Sepatu Bola Nike Mercurial Superfly",
                "sku" => "SHOE-FB-NIKE-001",
                "description" => "Sepatu bola premium dengan teknologi flyknit yang ringan dan nyaman.",
                "search_keyword" => "sepatu bola, nike mercurial",
                "order_type" => "REGULER",
                "indent_days" => 0,
                "weight" => 0.8,
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
    #[OA\Response(response: 201, description: "Product created and pushed to channel")]
    public function store(\Modules\Product\Http\Requests\CreateProductRequest $request, string $channel): JsonResponse
    {
        $shopId = $request->input('shop_id');
        if (!$shopId) {
            return $this->errorResponse('shop_id wajib diisi', 400);
        }

        try {
            $res = $this->channelProductService->createAndPushProduct($request->validated(), $shopId);

            return $this->successResponse($res, 'Produk berhasil dibuat dan dikirim ke channel', 201);
        } catch (\Exception $e) {
            return $this->errorResponse('Gagal membuat dan mengirim produk', 500, ['error' => $e->getMessage()]);
        }
    }

    #[OA\Put(
        path: "/api/v1/{channel}/products/{id}",
        summary: "Update product info",
        description: "Update info produk di channel tertentu.",
        tags: ["Channel Products"]
    )]
    #[OA\Parameter(name: "channel", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(type: "object"))]
    #[OA\Response(response: 200, description: "Product updated successfully")]
    public function update(Request $request, string $channel, string $id): JsonResponse
    {
        $data = $request->validate([
            'shop_id' => 'required|string',
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $shopId = $data['shop_id'];
        unset($data['shop_id']);

        $res = $this->channelProductService->updateAndPushProduct($id, $shopId, $data);

        return $this->successResponse($res, 'Produk berhasil diperbarui');
    }

    #[OA\Delete(
        path: "/api/v1/{channel}/products/{id}",
        summary: "Delete product",
        description: "Hapus produk di channel tertentu.",
        tags: ["Channel Products"]
    )]
    #[OA\Parameter(name: "channel", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\Response(response: 200, description: "Product deleted successfully")]
    public function destroy(Request $request, string $channel, string $id): JsonResponse
    {
        $shopId = $request->input('shop_id');
        if (!$shopId) {
            return $this->errorResponse('shop_id wajib diisi', 400);
        }

        $this->channelProductService->deleteProduct($id, $shopId);

        return $this->successResponse(['success' => true], 'Produk berhasil dihapus');
    }

    #[OA\Put(
        path: "/api/v1/{channel}/products/{id}/activate",
        summary: "Activate product",
        description: "Aktifkan / publish produk.",
        tags: ["Channel Products"]
    )]
    #[OA\Parameter(name: "channel", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\Response(response: 200, description: "Product activated successfully")]
    public function activate(Request $request, string $channel, string $id): JsonResponse
    {
        $shopId = $request->input('shop_id');
        if (!$shopId) {
            return $this->errorResponse('shop_id wajib diisi', 400);
        }

        $this->channelProductService->activateProduct($id, $shopId);

        return $this->successResponse(['success' => true], 'Produk berhasil diaktifkan');
    }

    #[OA\Put(
        path: "/api/v1/{channel}/products/{id}/deactivate",
        summary: "Deactivate product",
        description: "Nonaktifkan produk.",
        tags: ["Channel Products"]
    )]
    #[OA\Parameter(name: "channel", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\Response(response: 200, description: "Product deactivated successfully")]
    public function deactivate(Request $request, string $channel, string $id): JsonResponse
    {
        $shopId = $request->input('shop_id');
        if (!$shopId) {
            return $this->errorResponse('shop_id wajib diisi', 400);
        }

        $this->channelProductService->deactivateProduct($id, $shopId);

        return $this->successResponse(['success' => true], 'Produk berhasil dinonaktifkan');
    }

    #[OA\Put(
        path: "/api/v1/{channel}/products/{id}/stock",
        summary: "Update stock",
        description: "Update stok SKU di channel. Wajib menyertakan shop_id.",
        tags: ["Channel Products"]
    )]
    #[OA\Parameter(name: "channel", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        type: "object",
        properties: [
            new OA\Property(property: "shop_id", type: "string")
        ]
    ))]
    #[OA\Response(response: 200, description: "Product stock updated successfully")]
    public function updateStock(Request $request, string $channel, string $id): JsonResponse
    {
        $shopId = $request->input('shop_id');
        if (!$shopId) {
            return $this->errorResponse('shop_id wajib diisi', 400);
        }

        $this->channelProductService->updateStock($id, $shopId);

        return $this->successResponse(['success' => true], 'Stok produk berhasil diperbarui');
    }

    #[OA\Put(
        path: "/api/v1/{channel}/products/{id}/price",
        summary: "Update price",
        description: "Update harga SKU di channel. Wajib menyertakan shop_id.",
        tags: ["Channel Products"]
    )]
    #[OA\Parameter(name: "channel", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        type: "object",
        properties: [
            new OA\Property(property: "shop_id", type: "string")
        ]
    ))]
    #[OA\Response(response: 200, description: "Product price updated successfully")]
    public function updatePrice(Request $request, string $channel, string $id): JsonResponse
    {
        $shopId = $request->input('shop_id');
        if (!$shopId) {
            return $this->errorResponse('shop_id wajib diisi', 400);
        }

        $this->channelProductService->updatePrice($id, $shopId);

        return $this->successResponse(['success' => true], 'Harga produk berhasil diperbarui');
    }

    #[OA\Delete(
        path: "/api/v1/{channel}/products/{id}/link",
        summary: "Unlink product from channel",
        description: "Putus koneksi produk dari 1 channel/toko tanpa menghapus produk lokal. Wajib shop_id.",
        tags: ["Channel Products"]
    )]
    #[OA\Parameter(name: "channel", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\Parameter(name: "id", in: "path", required: true, description: "external_product_id", schema: new OA\Schema(type: "string"))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: "shop_id", type: "string")
    ]))]
    #[OA\Response(response: 200, description: "Channel link removed")]
    #[OA\Response(response: 404, description: "Produk tidak terhubung ke channel ini")]
    #[OA\Response(response: 409, description: "Sedang proses sync")]
    public function unlink(Request $request, string $channel, string $id): JsonResponse
    {
        $shopId = $request->input('shop_id');
        if (!$shopId) {
            return $this->errorResponse('shop_id wajib diisi', 400);
        }

        try {
            $this->channelProductService->unlinkProduct($id, $shopId);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->successResponse(['success' => true], 'Koneksi channel berhasil diputus');
    }

    #[OA\Get(
        path: "/api/v1/{channel}/products/categories",
        summary: "List categories",
        description: "Daftar kategori channel.",
        tags: ["Channel Products"]
    )]
    #[OA\Parameter(name: "channel", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\Parameter(name: "parent_id", in: "query", required: false, schema: new OA\Schema(type: "string"))]
    #[OA\Response(response: 200, description: "Get categories success")]
    public function categories(Request $request, string $channel): JsonResponse
    {
        return $this->successResponse([], 'Get categories success');
    }
}
