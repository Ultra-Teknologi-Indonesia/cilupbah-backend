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
use Modules\Product\Http\Resources\ProductChannelListingResource;
use Modules\Product\Http\Resources\ProductChannelPriceResource;
use Modules\Product\Http\Resources\ProductPriceBookResource;
use Modules\Product\Http\Resources\ProductResource;
use Modules\Product\Http\Resources\ProductStockResource;
use Modules\Product\Http\Resources\ProductVariantRowResource;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductWholesalePrice;
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
        'variants.options',
        'bundleItems.component.product:id,name',
        'bundleItems.component.options',
        'bundleItems.component.inventories',
        'variationTypes.attribute',
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

    #[OA\Get(
        path: '/api/v1/products/{id}/variants',
        summary: 'Daftar varian produk (paginasi + search + sort)',
        tags: ['Products'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Cari SKU / nilai opsi', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, description: 'sku | sell_price | stock (awali - untuk desc)', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK'),
            new OA\Response(response: 404, description: 'Produk tidak ditemukan'),
        ]
    )]
    public function variants(Request $request, $id): JsonResponse
    {
        $product = $this->findProduct($id);
        if (! $product) {
            return $this->errorResponse('Produk tidak ditemukan', 404);
        }

        $search = trim((string) $request->query('search', ''));
        $sort = (string) $request->query('sort', 'sku');
        $dir = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $col = ltrim($sort, '-');
        $perPage = min(max((int) $request->integer('per_page', 20), 1), 200);

        $query = ProductVariant::query()
            ->where('product_id', $product->id)
            ->with('options')
            ->withSum('inventories', 'available');

        if ($search !== '') {
            $query->where(function ($w) use ($search) {
                $w->where('sku', 'ilike', "%{$search}%")
                    ->orWhereHas('options', fn ($o) => $o->where('value', 'ilike', "%{$search}%"));
            });
        }

        match ($col) {
            'sell_price' => $query->orderBy('sell_price', $dir),
            // NULLS LAST: varian tanpa baris inventory (sum null) tidak melompat ke atas saat desc.
            'stock' => $query->orderByRaw('inventories_sum_available ' . ($dir === 'desc' ? 'desc' : 'asc') . ' nulls last'),
            default => $query->orderBy('sku', $dir),
        };

        return $this->successPaginatedResponse(
            ProductVariantRowResource::collection($query->paginate($perPage)),
            'Daftar varian berhasil diambil.'
        );
    }

    /**
     * Aksi massal varian: activate | deactivate | delete (by ids, milik produk ini).
     * delete diproteksi: tolak varian yang sudah ter-listing channel / punya inventory,
     * dan jangan sisakan 0 varian.
     */
    public function bulkVariants(Request $request, $id): JsonResponse
    {
        $product = $this->findProduct($id);
        if (! $product) {
            return $this->errorResponse('Produk tidak ditemukan', 404);
        }

        $data = $request->validate([
            'action' => 'required|in:activate,deactivate,delete',
            'variant_ids' => 'required|array|min:1',
            'variant_ids.*' => 'uuid',
        ]);

        $variants = ProductVariant::where('product_id', $product->id)
            ->whereIn('id', $data['variant_ids'])
            ->get(['id', 'sku']);

        if ($variants->isEmpty()) {
            return $this->errorResponse('Tidak ada varian yang cocok untuk produk ini.', 422);
        }

        if ($data['action'] !== 'delete') {
            ProductVariant::whereIn('id', $variants->pluck('id'))
                ->update(['is_active' => $data['action'] === 'activate', 'updated_at' => now()]);

            return $this->successResponse(
                ['affected' => $variants->count()],
                'Status varian diperbarui.'
            );
        }

        // delete — proteksi integritas
        $total = ProductVariant::where('product_id', $product->id)->count();
        if ($variants->count() >= $total) {
            return $this->errorResponse('Tidak bisa menghapus semua varian; produk butuh minimal 1 varian.', 422);
        }

        $blocked = [];
        $deletable = [];
        foreach ($variants as $v) {
            $listed = \Illuminate\Support\Facades\DB::table('product_variant_channel_mappings')->where('variant_id', $v->id)->exists();
            $hasStock = \Illuminate\Support\Facades\DB::table('inventories')->where('item_id', $v->id)->exists();
            if ($listed || $hasStock) {
                $blocked[] = $v->sku;
            } else {
                $deletable[] = $v->id;
            }
        }

        if (empty($deletable)) {
            return $this->errorResponse(
                'Varian sudah ter-listing di channel atau punya stok — tidak bisa dihapus: ' . implode(', ', $blocked),
                422,
                ['blocked' => $blocked]
            );
        }

        ProductVariant::whereIn('id', $deletable)->delete();

        return $this->successResponse(
            ['deleted' => count($deletable), 'blocked' => $blocked],
            'Varian dihapus.' . ($blocked ? ' Sebagian dilewati karena terpakai.' : '')
        );
    }

    #[OA\Get(
        path: '/api/v1/products/{id}/channel-listings',
        summary: 'Listing channel per varian (paginasi + filter channel)',
        tags: ['Products'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'channel', in: 'query', required: false, description: 'Kode channel (mis. lazada)', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'include_unlisted', in: 'query', required: false, description: 'Sertakan varian tanpa listing (default false)', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK'),
            new OA\Response(response: 404, description: 'Produk tidak ditemukan'),
        ]
    )]
    public function channelListings(Request $request, $id): JsonResponse
    {
        $product = $this->findProduct($id);
        if (! $product) {
            return $this->errorResponse('Produk tidak ditemukan', 404);
        }

        $channel = $request->query('channel');
        $perPage = min(max((int) $request->integer('per_page', 20), 1), 200);

        $query = ProductVariant::query()
            ->where('product_id', $product->id)
            ->with(['options', 'channelMappings.channelMapping.channelShop.channel'])
            ->orderBy('sku');

        // Default: hanya varian yang punya listing (listed_only) — sesuai UX.
        if (! $request->boolean('include_unlisted')) {
            $query->whereHas('channelMappings.channelMapping', function ($q) use ($channel) {
                if ($channel) {
                    $q->whereHas('channelShop.channel', fn ($c) => $c->where('code', $channel));
                }
            });
        }

        return $this->successPaginatedResponse(
            ProductChannelListingResource::collection($query->paginate($perPage)),
            'Daftar listing channel berhasil diambil.'
        );
    }

    #[OA\Get(
        path: '/api/v1/products/{id}/channel-prices',
        summary: 'Harga channel per varian (internal + override per toko)',
        tags: ['Products'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'channel', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'include_unlisted', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK'),
            new OA\Response(response: 404, description: 'Produk tidak ditemukan'),
        ]
    )]
    public function channelPrices(Request $request, $id): JsonResponse
    {
        $product = $this->findProduct($id);
        if (! $product) {
            return $this->errorResponse('Produk tidak ditemukan', 404);
        }

        $channel = $request->query('channel');
        $perPage = min(max((int) $request->integer('per_page', 20), 1), 200);

        $query = ProductVariant::query()
            ->where('product_id', $product->id)
            ->with(['options', 'channelMappings.channelMapping.channelShop.channel'])
            ->orderBy('sku');

        if (! $request->boolean('include_unlisted')) {
            $query->whereHas('channelMappings.channelMapping', function ($q) use ($channel) {
                if ($channel) {
                    $q->whereHas('channelShop.channel', fn ($c) => $c->where('code', $channel));
                }
            });
        }

        return $this->successPaginatedResponse(
            ProductChannelPriceResource::collection($query->paginate($perPage)),
            'Harga channel berhasil diambil.'
        );
    }

    #[OA\Get(
        path: '/api/v1/products/{id}/price-book',
        summary: 'Buku harga (grosir) per varian',
        tags: ['Products'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK'),
            new OA\Response(response: 404, description: 'Produk tidak ditemukan'),
        ]
    )]
    public function priceBook(Request $request, $id): JsonResponse
    {
        $product = $this->findProduct($id);
        if (! $product) {
            return $this->errorResponse('Produk tidak ditemukan', 404);
        }

        $perPage = min(max((int) $request->integer('per_page', 20), 1), 200);

        $query = ProductWholesalePrice::query()
            ->whereHas('variant', fn ($v) => $v->where('product_id', $product->id))
            ->with('variant:id,sku')
            ->orderBy('variant_id')
            ->orderBy('min_qty');

        return $this->successPaginatedResponse(
            ProductPriceBookResource::collection($query->paginate($perPage)),
            'Buku harga berhasil diambil.'
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
