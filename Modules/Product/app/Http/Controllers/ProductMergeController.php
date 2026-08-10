<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Modules\Product\Http\Requests\ApplyMergeRequest;
use Modules\Product\Http\Requests\AutoMergeRequest;
use Modules\Product\Http\Requests\BulkMasterNamesRequest;
use Modules\Product\Http\Requests\BulkMergeProductsRequest;
use Modules\Product\Http\Requests\CatalogQueryRequest;
use Modules\Product\Http\Requests\MergeQueryRequest;
use Modules\Product\Http\Requests\UnmergeMasterRequest;
use Modules\Product\Http\Resources\AppliedMergeResource;
use Modules\Product\Http\Resources\MergeGroupResource;
use Modules\Product\Http\Resources\MergeSuggestionResource;
use Modules\Product\Services\ProductMergeService;

class ProductMergeController extends Controller
{
    use ApiResponse;

    public function __construct(private ProductMergeService $service) {}

    public function catalog(CatalogQueryRequest $request): JsonResponse
    {
        $result = $this->service->catalog(
            $request->filter(),
            $request->search(),
            $request->pageNumber(),
            $request->perPage(),
        );

        return $this->successResponse(
            MergeGroupResource::collection($result['rows'])->resolve($request),
            'Get product catalog success',
            200,
            [
                'current_page' => $result['page'],
                'per_page' => $result['limit'],
                'total' => $result['total'],
                'last_page' => (int) max(1, ceil($result['total'] / $result['limit'])),
                'counts' => $result['counts'],
            ],
        );
    }

    public function suggestions(MergeQueryRequest $request): JsonResponse
    {
        $data = $this->service->suggestions($request->search());

        return $this->successResponse(
            MergeSuggestionResource::collection($data)->resolve($request),
            'Get merge suggestions success',
        );
    }

    public function applied(MergeQueryRequest $request): JsonResponse
    {
        $data = $this->service->listMerges($request->search());

        return $this->successResponse(
            AppliedMergeResource::collection($data)->resolve($request),
            'Get applied merges success',
        );
    }

    public function auto(AutoMergeRequest $request): JsonResponse
    {
        $result = $this->service->autoMergeAll($request->validated()['name_pattern_groups'] ?? null);

        return $this->successResponse($result, "Auto-merge {$result['merged']} produk ke {$result['groups_affected']} master group");
    }

    public function apply(ApplyMergeRequest $request): JsonResponse
    {
        $data = $request->validated();

        return $this->guard(function () use ($data) {
            $result = $this->service->applyMerge($data['master_name'], $data['product_ids']);

            return $this->successResponse($result, "{$result['merged']} produk di-merge ke master \"{$result['master_name']}\"");
        });
    }

    public function bulk(BulkMergeProductsRequest $request): JsonResponse
    {
        $data = $request->validated();

        return $this->guard(function () use ($data) {
            $result = $this->service->bulkMergeProducts($data['master_name'], $data['product_names']);

            return $this->successResponse($result, "{$result['merged']} produk di-merge ke master \"{$result['master_name']}\"");
        });
    }

    public function unmerge(string $product): JsonResponse
    {
        $result = $this->service->unmerge($product);

        return $this->successResponse($result, 'Produk dilepas dari master');
    }

    public function unmergeMaster(UnmergeMasterRequest $request): JsonResponse
    {
        $result = $this->service->unmergeMaster($request->validated()['master_name']);

        return $this->successResponse($result, "{$result['removed']} produk di-unmerge");
    }

    public function bulkUnmerge(BulkMasterNamesRequest $request): JsonResponse
    {
        $result = $this->service->bulkUnmergeMasters($request->validated()['master_names']);

        return $this->successResponse($result, "{$result['masters']} master di-unmerge ({$result['removed']} produk kembali ke nama asli)");
    }

    public function hide(BulkMasterNamesRequest $request): JsonResponse
    {
        $result = $this->service->bulkHide($request->validated()['master_names']);

        return $this->successResponse($result, "{$result['hidden']} produk di-hide");
    }

    public function unhide(BulkMasterNamesRequest $request): JsonResponse
    {
        $result = $this->service->bulkUnhide($request->validated()['master_names']);

        return $this->successResponse($result, "{$result['unhidden']} produk di-unhide");
    }

    private function guard(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (DomainException $e) {
            return $this->errorResponse(
                'Gagal memproses aksi.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }
}
