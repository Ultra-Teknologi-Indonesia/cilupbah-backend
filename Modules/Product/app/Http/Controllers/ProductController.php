<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\Product\Http\Requests\CreateProductRequest;
use Modules\Product\Http\Requests\ItemIdsRequest;
use Modules\Product\Http\Requests\StoreBundleRequest;
use Modules\Product\Http\Requests\UpdateProductRequest;
use Modules\Product\Http\Resources\ProductPriceResource;
use Modules\Product\Http\Resources\ProductResource;
use Modules\Product\Http\Resources\ProductStockResource;
use Modules\Product\Models\Product;
use Modules\Product\Repositories\ProductRepository;
use Modules\Product\Services\ProductService;
use Modules\Product\Services\ProductLifecycleService;
use OpenApi\Attributes as OA;
use App\Traits\ApiResponse;

class ProductController extends Controller
{
    use ApiResponse;

    private const DETAIL_RELATIONS = [
        'variants.channelMappings.channelMapping',
        'variants.salesTax',
        'variants.purchaseTax',
        'variants.unlimitedShops',
        'variants.inventories',
        'media',
        'category',
        'brand',
        'salesAccount',
        'salesReturnAccount',
        'inventoryAccount',
        'cogsAccount',
        'channelMappings.channelShop.channel',
        'archivedBy',
    ];

    protected ProductService $productService;
    protected ProductLifecycleService $lifecycleService;
    protected ProductRepository $productRepository;

    public function __construct(
        ProductService $productService,
        ProductLifecycleService $lifecycleService,
        ProductRepository $productRepository
    ) {
        $this->productService = $productService;
        $this->lifecycleService = $lifecycleService;
        $this->productRepository = $productRepository;
    }

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
        $status = $request->query('status');
        if ($status !== null && !in_array($status, Product::STATUSES, true)) {
            return $this->errorResponse('Status tidak valid', 422, [
                'status' => ['Nilai harus salah satu dari: ' . implode(', ', Product::STATUSES)],
            ]);
        }

        $products = $this->productRepository->paginateIndex($status);

        return $this->successPaginatedResponse(ProductResource::collection($products), 'Get products success');
    }

    #[OA\Get(
        path: '/api/v1/products/uploadable',
        summary: 'List uploadable products (belum ter-mapping ke shop)',
        tags: ['Products'],
        parameters: [
            new OA\Parameter(name: 'channel', in: 'query', required: false, description: 'Kode channel (informasional)', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'shop_id', in: 'query', required: true, description: 'shop_id marketplace tujuan', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 422, description: 'Toko tidak ditemukan / shop_id kosong')
        ]
    )]
    public function uploadable(Request $request): JsonResponse
    {
        $request->validate([
            'shop_id' => 'required|string',
            'channel' => 'nullable|string',
        ]);

        $channelShopId = $this->productService->resolveChannelShopId($request->query('shop_id'));
        if (!$channelShopId) {
            return $this->errorResponse('Toko tidak ditemukan atau tidak aktif', 422);
        }

        $products = $this->productRepository->paginateUploadable($channelShopId);

        return $this->successPaginatedResponse(ProductResource::collection($products), 'Get uploadable products success');
    }

    public function store(CreateProductRequest $request): JsonResponse
    {
        try {
            $productId = $this->productService->createProduct($request->validated());
            return $this->successResponse([
                'product_id' => $productId
            ], 'Product created successfully', 201);
        } catch (\DomainException $e) {
            // Pelanggaran aturan bisnis (mis. invariant varian) → 422, bukan 500.
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to create product', 500, ['error' => $e->getMessage()]);
        }
    }

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
        $product = $this->findProduct($id, self::DETAIL_RELATIONS);
        if (!$product) {
            return $this->errorResponse('Produk tidak ditemukan', 404);
        }

        return $this->successResponse(new ProductResource($product), 'Get product detail success');
    }

    #[OA\Put(
        path: '/api/v1/products/{id}',
        summary: 'Update product',
        tags: ['Products'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Product updated'),
            new OA\Response(response: 404, description: 'Product not found')
        ]
    )]
    public function update(UpdateProductRequest $request, $id): JsonResponse
    {
        $product = $this->findProduct($id);
        if (!$product) {
            return $this->errorResponse('Produk tidak ditemukan', 404);
        }

        try {
            $this->productService->updateProduct($id, $request->validated());
        } catch (\DomainException $e) {
            // Pelanggaran aturan bisnis (mis. immutability/invariant varian) → 422, bukan 500.
            return $this->errorResponse($e->getMessage(), 422);
        }

        return $this->successResponse(
            new ProductResource($product->fresh(self::DETAIL_RELATIONS)),
            'Produk berhasil diperbarui'
        );
    }

    public function destroy($id): JsonResponse
    {
        $product = $this->findProduct($id);
        if (!$product) {
            return $this->errorResponse('Produk tidak ditemukan', 404);
        }

        try {
            $this->productService->deleteProduct($product);
        } catch (\DomainException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        return $this->successResponse(null, 'Produk berhasil dihapus');
    }

    #[OA\Post(
        path: '/api/v1/products/{id}/approve',
        summary: 'Jadikan produk Master (download → master)',
        tags: ['Products'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Product approved'),
            new OA\Response(response: 422, description: 'Invalid state / validation'),
            new OA\Response(response: 404, description: 'Product not found')
        ]
    )]
    public function approve(Request $request, $id): JsonResponse
    {
        return $this->runLifecycle($id, fn (Product $product) => $this->lifecycleService->approve($product, $request->user()?->id), 'Produk berhasil dijadikan Master');
    }

    #[OA\Post(
        path: '/api/v1/products/{id}/archive',
        summary: 'Archive product',
        tags: ['Products'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'reason', type: 'string', nullable: true)
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Product archived'),
            new OA\Response(response: 422, description: 'Invalid state'),
            new OA\Response(response: 404, description: 'Product not found')
        ]
    )]
    public function archive(Request $request, $id): JsonResponse
    {
        $validated = $request->validate(['reason' => 'nullable|string|max:255']);

        return $this->runLifecycle(
            $id,
            fn (Product $product) => $this->lifecycleService->archive($product, $validated['reason'] ?? null, $request->user()?->id),
            'Produk berhasil diarsipkan'
        );
    }

    #[OA\Post(
        path: '/api/v1/products/{id}/restore',
        summary: 'Restore product from archive',
        tags: ['Products'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Product restored'),
            new OA\Response(response: 422, description: 'Invalid state'),
            new OA\Response(response: 404, description: 'Product not found')
        ]
    )]
    public function restore(Request $request, $id): JsonResponse
    {
        return $this->runLifecycle($id, fn (Product $product) => $this->lifecycleService->restore($product), 'Produk berhasil dipulihkan ke Master');
    }

    private function runLifecycle($id, callable $action, string $message): JsonResponse
    {
        $product = $this->findProduct($id);
        if (!$product) {
            return $this->errorResponse('Produk tidak ditemukan', 404);
        }

        try {
            $action($product);
        } catch (\DomainException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        return $this->successResponse(
            new ProductResource($product->fresh(self::DETAIL_RELATIONS)),
            $message
        );
    }

    public function showBySku(string $sku): JsonResponse
    {
        $product = $this->productService->getProductBySku($sku);

        if (! $product) {
            return $this->errorResponse('Produk dengan SKU tersebut tidak ditemukan', 404);
        }

        return $this->successResponse(new ProductResource($product), 'Get product by SKU success');
    }

    public function bundles(): JsonResponse
    {
        return $this->successPaginatedResponse(
            ProductResource::collection($this->productService->getBundles()),
            'Get product bundles success'
        );
    }

    public function allStocks(ItemIdsRequest $request): JsonResponse
    {
        $products = $this->productService->getStocksByIds($request->validated()['item_ids']);

        return $this->successResponse(ProductStockResource::collection($products), 'Get product stocks success');
    }

    public function prices(ItemIdsRequest $request): JsonResponse
    {
        $products = $this->productService->getPricesByIds($request->validated()['item_ids']);

        return $this->successResponse(ProductPriceResource::collection($products), 'Get product prices success');
    }

    public function storeBundle(StoreBundleRequest $request): JsonResponse
    {
        $data = $request->validated();
        $product = $this->productService->createOrUpdateBundle($data);
        $isUpdate = isset($data['id']);

        return $this->successResponse(
            ['product_id' => $product->id],
            $isUpdate ? 'Bundle produk berhasil diperbarui' : 'Bundle produk berhasil dibuat',
            $isUpdate ? 200 : 201,
        );
    }

    private function findProduct($id, array $with = []): ?Product
    {
        $normalizedId = str_replace('-', '', (string) $id);
        if (strlen($normalizedId) !== 32 || !ctype_xdigit($normalizedId)) {
            return null;
        }

        return $this->productRepository->findWithRelations($id, $with);
    }
}
