<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\Product\Http\Requests\StoreChannelDraftRequest;
use Modules\Product\Http\Resources\ProductChannelDraftResource;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelDraft;
use Modules\Product\Services\ProductChannelDraftService;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use OpenApi\Attributes as OA;
use App\Traits\ApiResponse;

class ProductChannelDraftController extends Controller
{
    use ApiResponse;

    protected ProductChannelDraftService $draftService;

    public function __construct(ProductChannelDraftService $draftService)
    {
        $this->draftService = $draftService;
    }

    /**
     * Daftar semua draft (sub-tab Draft di tab Naikkan Produk), filter ?status=.
     */
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
        $drafts = QueryBuilder::for(ProductChannelDraft::class)
            ->with('channelShop')
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('channel_shop_id'),
                AllowedFilter::exact('product_id'),
            )
            ->allowedSorts('created_at', 'updated_at')
            ->defaultSort('-created_at')
            ->paginate($request->input('limit', 10));

        return $this->successPaginatedResponse(
            ProductChannelDraftResource::collection($drafts),
            'Get channel drafts success'
        );
    }

    /**
     * Daftar draft untuk satu produk.
     */
    #[OA\Get(
        path: '/api/v1/products/{id}/channel-drafts',
        summary: 'List draft listing per produk',
        tags: ['Product Drafts'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Success'), new OA\Response(response: 404, description: 'Product not found')]
    )]
    public function index($id): JsonResponse
    {
        if (!$this->findProduct($id)) {
            return $this->errorResponse('Produk tidak ditemukan', 404);
        }

        $drafts = ProductChannelDraft::with('channelShop')
            ->where('product_id', $id)
            ->get();

        return $this->successResponse(
            ProductChannelDraftResource::collection($drafts),
            'Get channel drafts success'
        );
    }

    /**
     * Buat / perbarui draft untuk satu produk + toko.
     */
    #[OA\Post(
        path: '/api/v1/products/{id}/channel-drafts',
        summary: 'Simpan draft listing (upsert per product+shop)',
        tags: ['Product Drafts'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 201, description: 'Draft saved'),
            new OA\Response(response: 404, description: 'Product not found'),
            new OA\Response(response: 422, description: 'Validation / toko tidak ditemukan')
        ]
    )]
    public function store(StoreChannelDraftRequest $request, $id): JsonResponse
    {
        $product = $this->findProduct($id);
        if (!$product) {
            return $this->errorResponse('Produk tidak ditemukan', 404);
        }

        try {
            $draft = $this->draftService->upsertDraft(
                $product->id,
                $request->validated()['shop_id'],
                $request->validated(),
                $request->user()?->id
            );
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->successResponse(
            new ProductChannelDraftResource($draft->load('channelShop')),
            'Draft berhasil disimpan',
            201
        );
    }

    /**
     * Perbarui field draft tertentu.
     */
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
        $draft = $this->findDraft($id, $draftId);
        if (!$draft) {
            return $this->errorResponse('Draft tidak ditemukan', 404);
        }

        $data = $request->validate([
            'channel_category_id' => 'sometimes|nullable|string',
            'attribute_mapping' => 'sometimes|nullable|array',
            'price_override' => 'sometimes|nullable|numeric|min:0',
            'status' => 'sometimes|in:draft,ready,cancelled',
        ]);

        $draft->update($data);

        return $this->successResponse(
            new ProductChannelDraftResource($draft->fresh('channelShop')),
            'Draft berhasil diperbarui'
        );
    }

    /**
     * Hapus draft.
     */
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
        $draft = $this->findDraft($id, $draftId);
        if (!$draft) {
            return $this->errorResponse('Draft tidak ditemukan', 404);
        }

        $draft->delete();

        return $this->successResponse(['success' => true], 'Draft berhasil dihapus');
    }

    private function findProduct($id): ?Product
    {
        if (!$this->isUuid($id)) {
            return null;
        }

        return Product::find($id);
    }

    private function findDraft($productId, $draftId): ?ProductChannelDraft
    {
        if (!$this->isUuid($productId) || !$this->isUuid($draftId)) {
            return null;
        }

        return ProductChannelDraft::where('id', $draftId)
            ->where('product_id', $productId)
            ->first();
    }

    private function isUuid($value): bool
    {
        $normalized = str_replace('-', '', (string) $value);

        return strlen($normalized) === 32 && ctype_xdigit($normalized);
    }
}
