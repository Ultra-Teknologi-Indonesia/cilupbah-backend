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
use Modules\Product\Http\Resources\ProductPriceBookResource;
use Modules\Product\Http\Resources\ProductResource;
use Modules\Product\Http\Resources\ProductStockResource;
use Modules\Product\Http\Resources\ProductVariantRowResource;
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
        'variants.inventories.bin:id,is_inbound',
        'variants.options',
        'bundleItems.component.product:id,name',
        'bundleItems.component.options',
        'bundleItems.component.inventories',
        'bundleItems.component.inventories.bin:id,is_inbound',
        'bundleItems.component.channelMappings',
        'variationTypes.attribute',
        'media',
        'category',
        'salesAccount',
        'salesReturnAccount',
        'inventoryAccount',
        'cogsAccount',
        'channelMappings.channelShop.channel',
        'archivedBy',
        'specifications.attributeOption',
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
            return $this->errorResponse($e->getMessage(), 422, null, 'Gagal menyimpan produk');
        } catch (\Illuminate\Database\QueryException $e) {
            return $this->errorResponse(
                $this->humanizeDbError($e),
                422,
                null,
                'Gagal menyimpan produk',
            );
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
            return $this->errorResponse($e->getMessage(), 422, null, 'Gagal memperbarui produk');
        } catch (\Illuminate\Database\QueryException $e) {
            return $this->errorResponse($this->humanizeDbError($e), 422, null, 'Gagal memperbarui produk');
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
            return $this->errorResponse(
                'Gagal menghapus.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
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

    public function bulkArchive(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1|max:50',
            'ids.*' => 'uuid|exists:products,id',
            'reason' => 'nullable|string|max:255',
        ]);

        $result = $this->lifecycleService->bulkArchive(
            $validated['ids'],
            $validated['reason'] ?? null,
            $request->user()?->id
        );

        return $this->successResponse($result, "{$result['success']} produk berhasil diarsipkan");
    }

    public function bulkRestore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1|max:50',
            'ids.*' => 'uuid|exists:products,id',
        ]);

        $result = $this->lifecycleService->bulkRestore($validated['ids']);

        return $this->successResponse($result, "{$result['success']} produk berhasil dipulihkan");
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1|max:50',
            'ids.*' => 'uuid|exists:products,id',
        ]);

        $result = $this->productService->bulkDelete($validated['ids']);

        return $this->successResponse($result, "{$result['success']} produk berhasil dihapus");
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
            return $this->errorResponse($e->getMessage(), 422, null, 'Aksi tidak dapat diproses');
        }

        return $this->successResponse(
            new ProductResource($product->fresh(self::DETAIL_RELATIONS)),
            $message
        );
    }

    private function humanizeDbError(\Illuminate\Database\QueryException $e): string
    {
        $sqlState = $e->errorInfo[0] ?? null;
        $detail = (string) ($e->errorInfo[2] ?? $e->getMessage());

        if ($sqlState === '23505') {
            if (stripos($detail, 'sku') !== false) {
                return 'SKU sudah digunakan produk lain. Gunakan SKU yang berbeda.';
            }

            return 'Data yang Anda masukkan sudah ada (duplikat). Periksa kembali isian yang harus unik.';
        }

        if ($sqlState === '23502') {
            return 'Ada isian wajib yang belum lengkap. Periksa kembali data produk (mis. foto atau field yang kosong).';
        }

        if ($sqlState === '23503') {
            return 'Data yang dipilih tidak valid atau sudah tidak tersedia (mis. kategori/atribut). Muat ulang halaman lalu coba lagi.';
        }

        \Illuminate\Support\Facades\Log::error('Product save DB error', [
            'sql_state' => $sqlState,
            'detail'    => $detail,
        ]);

        return 'Terjadi kendala saat menyimpan produk. Periksa kembali isian Anda lalu coba lagi.';
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

        try {
            $product = $this->productService->createOrUpdateBundle($data);
        } catch (\DomainException $e) {
            return $this->errorResponse(
                'Gagal menyimpan.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }

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

        return $this->successPaginatedResponse(
            ProductVariantRowResource::collection($this->productRepository->paginateVariants($product->id)),
            'Daftar varian berhasil diambil.'
        );
    }

    public function bulkVariants(Request $request, $id): JsonResponse
    {
        $product = $this->findProduct($id);
        if (! $product) {
            return $this->errorResponse('Produk tidak ditemukan', 404);
        }

        $data = $request->validate([
            'action' => 'required|in:delete',
            'variant_ids' => 'required|array|min:1',
            'variant_ids.*' => 'uuid',
        ]);

        try {
            $result = $this->productService->bulkUpdateVariants($product, $data['action'], $data['variant_ids']);
        } catch (\DomainException $e) {
            return $this->errorResponse(
                'Gagal memproses batch.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }

        return $this->successResponse($result, 'Aksi massal varian berhasil.');
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

        return $this->successPaginatedResponse(
            ProductChannelListingResource::collection($this->productRepository->paginateListedVariants($product->id)),
            'Daftar listing channel berhasil diambil.'
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

        return $this->successPaginatedResponse(
            ProductPriceBookResource::collection($this->productRepository->paginatePriceBook($product->id)),
            'Buku harga berhasil diambil.'
        );
    }

    #[OA\Delete(
        path: '/api/v1/products/{id}/channel-mappings/{mappingId}',
        summary: 'Putus tautan produk dari channel shop (Unlink)',
        tags: ['Products'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'mappingId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Tautan channel berhasil dihapus'),
            new OA\Response(response: 404, description: 'Tautan channel tidak ditemukan'),
            new OA\Response(response: 409, description: 'Sedang dalam proses sinkronisasi'),
        ]
    )]
    public function unlinkChannelMapping(Request $request, string $id, string $mappingId): JsonResponse
    {
        $product = $this->findProduct($id);
        if (! $product) {
            return $this->errorResponse('Produk tidak ditemukan', 404);
        }

        $mapping = \Modules\Product\Models\ProductChannelMapping::where('id', $mappingId)
            ->where('product_id', $product->id)
            ->first();

        if (! $mapping) {
            return $this->errorResponse('Tautan channel tidak ditemukan.', 404);
        }

        if ($mapping->sync_status === 'syncing') {
            return $this->errorResponse('Tidak dapat menghapus tautan saat proses sinkronisasi sedang berjalan.', 409);
        }

        $shopId = $mapping->channel_shop_id;
        $mapping->delete();

        \Modules\Product\Models\ProductSyncLog::record([
            'product_id' => $product->id,
            'channel_shop_id' => $shopId,
            'action' => \Modules\Product\Models\ProductSyncLog::ACTION_UNLINK,
            'status' => \Modules\Product\Models\ProductSyncLog::STATUS_SUCCESS,
        ]);

        return $this->successResponse(['success' => true], 'Tautan channel berhasil dihapus.');
    }

    #[OA\Delete(
        path: '/api/v1/products/{id}/variant-channel-mappings/{variantMappingId}',
        summary: 'Putus tautan satu varian dari channel shop (Unlink Variant)',
        tags: ['Products'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'variantMappingId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Tautan varian channel berhasil dihapus'),
            new OA\Response(response: 404, description: 'Tautan varian tidak ditemukan'),
        ]
    )]
    public function unlinkVariantChannelMapping(Request $request, string $id, string $variantMappingId): JsonResponse
    {
        $product = $this->findProduct($id);
        if (! $product) {
            return $this->errorResponse('Produk tidak ditemukan', 404);
        }

        $variantMapping = \Modules\Product\Models\ProductVariantChannelMapping::where('id', $variantMappingId)
            ->whereHas('variant', function ($q) use ($product) {
                $q->where('product_id', $product->id);
            })
            ->first();

        if (! $variantMapping) {
            return $this->errorResponse('Tautan varian channel tidak ditemukan.', 404);
        }

        $parentMappingId = $variantMapping->product_channel_mapping_id;
        $variantMapping->delete();

        $remaining = \Modules\Product\Models\ProductVariantChannelMapping::where('product_channel_mapping_id', $parentMappingId)->count();
        if ($remaining === 0) {
            \Modules\Product\Models\ProductChannelMapping::where('id', $parentMappingId)->delete();
        }

        return $this->successResponse(['success' => true], 'Tautan varian channel berhasil dihapus.');
    }

    #[OA\Post(
        path: '/api/v1/products/{id}/channel-mappings/{mappingId}/re-sync',
        summary: 'Jadwalkan sinkronisasi ulang produk ke channel shop',
        tags: ['Products'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'mappingId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Sinkronisasi berhasil dijadwalkan'),
            new OA\Response(response: 404, description: 'Tautan channel tidak ditemukan'),
        ]
    )]
    public function resyncChannelMapping(Request $request, string $id, string $mappingId): JsonResponse
    {
        $product = $this->findProduct($id);
        if (! $product) {
            return $this->errorResponse('Produk tidak ditemukan', 404);
        }

        $mapping = \Modules\Product\Models\ProductChannelMapping::where('id', $mappingId)
            ->where('product_id', $product->id)
            ->first();

        if (! $mapping) {
            return $this->errorResponse('Tautan channel tidak ditemukan.', 404);
        }

        $mapping->markAsSyncing();
        \Modules\Channel\Jobs\SyncProductToChannelJob::dispatch($product->id, $mapping->channel_shop_id, 'sync_price_stock');

        \Modules\Product\Models\ProductSyncLog::record([
            'product_id' => $product->id,
            'channel_shop_id' => $mapping->channel_shop_id,
            'action' => \Modules\Product\Models\ProductSyncLog::ACTION_SYNC_STOCK,
            'status' => \Modules\Product\Models\ProductSyncLog::STATUS_PENDING,
        ]);

        return $this->successResponse(['success' => true], 'Sinkronisasi channel berhasil dijadwalkan.');
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
