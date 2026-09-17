<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Product\Http\Requests\StoreCatalogListingRequest;
use Modules\Product\Http\Requests\StoreChannelDraftRequest;
use Modules\Product\Http\Resources\ProductChannelDraftResource;
use Modules\Product\Models\ProductChannelDraft;
use Modules\Product\Services\ProductChannelDraftService;
use OpenApi\Attributes as OA;

class ProductChannelDraftController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected ProductChannelDraftService $draftService,
    ) {}

    #[OA\Get(
        path: '/api/v1/products/channel-drafts',
        summary: 'List semua draft listing',
        tags: ['Product Drafts'],
        parameters: [
            new OA\Parameter(name: 'filter[status]', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['draft', 'ready', 'cancelled'])),
            new OA\Parameter(name: 'filter[channel_shop_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Success')]
    )]
    public function list(Request $request): JsonResponse
    {
        $paginator = $this->draftService->paginate();

        $paginator->setCollection(
            $paginator->getCollection()->map(
                fn (ProductChannelDraft $draft) => (new ProductChannelDraftResource($draft))->resolve($request)
            )
        );

        return $this->successPaginatedResponse($paginator, 'Get channel drafts success');
    }

    public function upload(string $draftId): JsonResponse
    {
        try {
            $this->draftService->uploadDraft($draftId);
        } catch (ModelNotFoundException) {
            return $this->errorResponse('Draft tidak ditemukan', 404);
        } catch (DomainException $e) {
            return $this->errorResponse(
                'Gagal mengunggah.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }

        return $this->successResponse(null, 'Draft diantrekan untuk upload');
    }

    public function bulkUpload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'uuid',
        ]);

        $result = $this->draftService->bulkUpload($validated['ids']);

        return $this->successResponse($result, "{$result['uploaded']} draft diantrekan untuk upload");
    }

    #[OA\Get(
        path: '/api/v1/products/{id}/channel-drafts',
        summary: 'List draft listing per produk',
        tags: ['Product Drafts'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Success'), new OA\Response(response: 404, description: 'Product not found')]
    )]
    public function index($id): JsonResponse
    {
        if (! $this->draftService->productExists((string) $id)) {
            return $this->errorResponse('Produk tidak ditemukan', 404);
        }

        $drafts = $this->draftService->draftsForProduct($id);

        return $this->successResponse(
            ProductChannelDraftResource::collection($drafts),
            'Get channel drafts success'
        );
    }

    #[OA\Post(
        path: '/api/v1/products/{id}/channel-drafts',
        summary: 'Simpan draft listing (upsert per product+shop)',
        tags: ['Product Drafts'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 201, description: 'Draft saved'),
            new OA\Response(response: 404, description: 'Product not found'),
            new OA\Response(response: 422, description: 'Validation / toko tidak ditemukan'),
        ]
    )]
    public function store(StoreChannelDraftRequest $request, $id): JsonResponse
    {
        if (! $this->draftService->productExists((string) $id)) {
            return $this->errorResponse('Produk tidak ditemukan', 404);
        }

        try {
            $draft = $this->draftService->upsertDraft(
                $id,
                $request->validated()['shop_id'],
                $request->validated(),
                $request->user()?->id
            );
        } catch (\RuntimeException $e) {
            return $this->errorResponse(
                'Gagal memproses draft channel produk.',
                $e->getCode() ?: 422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }

        return $this->successResponse(
            new ProductChannelDraftResource($draft),
            'Draft berhasil disimpan',
            201
        );
    }

    public function bulkStore(Request $request, string $id): JsonResponse
    {
        if (! $this->draftService->productExists($id)) {
            return $this->errorResponse('Produk tidak ditemukan', 404);
        }

        $data = $request->validate([
            'shop_ids' => ['required', 'array', 'min:1', 'max:500'],
            'shop_ids.*' => ['required', 'string', 'distinct'],
            'channel_category_id' => ['nullable', 'string'],
            'attribute_mapping' => ['nullable', 'array'],
            'price_override' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:draft,ready,cancelled'],
        ]);

        $results = [];
        foreach (array_values(array_unique($data['shop_ids'])) as $shopId) {
            try {
                $draft = $this->draftService->upsertDraft(
                    $id,
                    $shopId,
                    $data,
                    $request->user()?->id,
                );
                $results[] = ['shop_id' => $shopId, 'status' => 'success', 'draft_id' => $draft->id];
            } catch (\Throwable $e) {
                $results[] = ['shop_id' => $shopId, 'status' => 'failed', 'message' => $e->getMessage()];
            }
        }

        $succeeded = count(array_filter($results, fn (array $result): bool => $result['status'] === 'success'));

        return $this->successResponse([
            'processed' => count($results),
            'succeeded' => $succeeded,
            'failed' => array_values(array_filter($results, fn (array $result): bool => $result['status'] === 'failed')),
            'draft_ids' => array_values(array_filter(array_column($results, 'draft_id'))),
            'results' => $results,
        ], "{$succeeded} draft berhasil disiapkan secara massal");
    }

    #[OA\Put(
        path: '/api/v1/products/{id}/channel-drafts/{draft}',
        summary: 'Update draft listing',
        tags: ['Product Drafts'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'draft', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Draft updated'), new OA\Response(response: 404, description: 'Not found')]
    )]
    public function update(Request $request, $id, $draftId): JsonResponse
    {
        $draft = $this->draftService->findDraftForProduct((string) $id, (string) $draftId);
        if (! $draft) {
            return $this->errorResponse('Draft tidak ditemukan', 404);
        }

        $data = $request->validate([
            'channel_category_id' => 'sometimes|nullable|string',
            'attribute_mapping' => 'sometimes|nullable|array',
            'price_override' => 'sometimes|nullable|numeric|min:0',
            'status' => 'sometimes|in:draft,ready,cancelled',
        ]);

        return $this->successResponse(
            new ProductChannelDraftResource($this->draftService->updateDraft($draft, $data)),
            'Draft berhasil diperbarui'
        );
    }

    #[OA\Delete(
        path: '/api/v1/products/{id}/channel-drafts/{draft}',
        summary: 'Hapus draft listing',
        tags: ['Product Drafts'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'draft', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Draft deleted'), new OA\Response(response: 404, description: 'Not found')]
    )]
    public function destroy($id, $draftId): JsonResponse
    {
        $draft = $this->draftService->findDraftForProduct((string) $id, (string) $draftId);
        if (! $draft) {
            return $this->errorResponse('Draft tidak ditemukan', 404);
        }

        $this->draftService->deleteDraft($draft);

        return $this->successResponse(['success' => true], 'Draft berhasil dihapus');
    }

    public function catalogListing(StoreCatalogListingRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $draft = $this->draftService->upsertDraft(
                $validated['product_id'],
                $validated['shop_id'],
                $validated,
                $request->user()?->id
            );
        } catch (\RuntimeException $e) {
            return $this->errorResponse(
                'Gagal memproses draft channel produk.',
                $e->getCode() ?: 422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }

        return $this->successResponse(
            new ProductChannelDraftResource($draft),
            'Listing produk berhasil disimpan',
            201
        );
    }

    public function requiredAttributes(Request $request, $id): JsonResponse
    {
        if (! $this->draftService->productExists((string) $id)) {
            return $this->errorResponse('Produk tidak ditemukan', 404);
        }

        $shopId = $request->query('shop_id');
        if (! $shopId) {
            return $this->errorResponse('Parameter shop_id wajib diisi', 422);
        }

        try {
            $result = $this->draftService->requiredAttributes($id, $shopId);
        } catch (\RuntimeException $e) {
            return $this->errorResponse(
                'Gagal memproses draft channel produk.',
                $e->getCode() ?: 422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }

        return $result['message'] !== null
            ? $this->successResponse($result['data'], $result['message'])
            : $this->successResponse($result['data']);
    }
}
