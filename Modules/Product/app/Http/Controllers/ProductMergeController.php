<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Product\Http\Requests\ApplyMergeRequest;
use Modules\Product\Http\Requests\AutoMergeRequest;
use Modules\Product\Http\Requests\BulkMasterNamesRequest;
use Modules\Product\Http\Requests\BulkMergeProductsRequest;
use Modules\Product\Services\ProductMergeService;

/**
 * Merge & Auto-Merge produk (port dari cilupbah-ops).
 * Merge bersifat lintas store & channel.
 */
class ProductMergeController extends Controller
{
    use ApiResponse;

    public function __construct(private ProductMergeService $service) {}

    /** Katalog produk ter-group (master + solo). */
    public function catalog(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'filter' => 'nullable|in:all,merged,unmerged,hidden',
            'q' => 'nullable|string',
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:500',
        ]);

        $result = $this->service->catalog(
            $validated['filter'] ?? 'all',
            (string) ($validated['q'] ?? ''),
            (int) ($validated['page'] ?? 1),
            (int) ($validated['limit'] ?? 50),
        );

        return $this->successResponse($result['rows'], 'Get product catalog success', 200, [
            'current_page' => $result['page'],
            'per_page' => $result['limit'],
            'total' => $result['total'],
            'last_page' => (int) max(1, ceil($result['total'] / $result['limit'])),
            'counts' => $result['counts'],
        ]);
    }

    /** Rekomendasi grup by signature nama + kode SKU. */
    public function suggestions(Request $request): JsonResponse
    {
        $data = $this->service->suggestions((string) $request->query('q', ''));

        return $this->successResponse($data, 'Get merge suggestions success');
    }

    /** Daftar merge aktif per master (lintas store/channel). */
    public function applied(Request $request): JsonResponse
    {
        $data = $this->service->listMerges((string) $request->query('q', ''));

        return $this->successResponse($data, 'Get applied merges success');
    }

    /** Auto-merge semua produk solo by kode awal SKU (sampai "-" pertama). */
    public function auto(AutoMergeRequest $request): JsonResponse
    {
        $result = $this->service->autoMergeAll($request->validated()['name_pattern_groups'] ?? null);

        return $this->successResponse($result, "Auto-merge {$result['merged']} produk ke {$result['groups_affected']} master group");
    }

    /** Merge daftar produk eksplisit → 1 master. */
    public function apply(ApplyMergeRequest $request): JsonResponse
    {
        $data = $request->validated();

        return $this->guard(function () use ($data) {
            $result = $this->service->applyMerge($data['master_name'], $data['product_ids']);

            return $this->successResponse($result, "{$result['merged']} produk di-merge ke master \"{$result['master_name']}\"");
        });
    }

    /** Merge berdasarkan nama produk (≥2) → 1 master. */
    public function bulk(BulkMergeProductsRequest $request): JsonResponse
    {
        $data = $request->validated();

        return $this->guard(function () use ($data) {
            $result = $this->service->bulkMergeProducts($data['master_name'], $data['product_names']);

            return $this->successResponse($result, "{$result['merged']} produk di-merge ke master \"{$result['master_name']}\"");
        });
    }

    /** Lepas 1 produk dari master-nya. */
    public function unmerge(string $product): JsonResponse
    {
        $result = $this->service->unmerge($product);

        return $this->successResponse($result, 'Produk dilepas dari master');
    }

    /** Hapus 1 master (semua produk kembali ke nama asli). */
    public function unmergeMaster(Request $request): JsonResponse
    {
        $validated = $request->validate(['master_name' => 'required|string']);
        $result = $this->service->unmergeMaster($validated['master_name']);

        return $this->successResponse($result, "{$result['removed']} produk di-unmerge");
    }

    /** Hapus banyak master sekaligus. */
    public function bulkUnmerge(BulkMasterNamesRequest $request): JsonResponse
    {
        $result = $this->service->bulkUnmergeMasters($request->validated()['master_names']);

        return $this->successResponse($result, "{$result['masters']} master di-unmerge ({$result['removed']} produk kembali ke nama asli)");
    }

    /** Hide master dari katalog non-hidden. */
    public function hide(BulkMasterNamesRequest $request): JsonResponse
    {
        $result = $this->service->bulkHide($request->validated()['master_names']);

        return $this->successResponse($result, "{$result['hidden']} produk di-hide");
    }

    /** Tampilkan kembali master hidden. */
    public function unhide(BulkMasterNamesRequest $request): JsonResponse
    {
        $result = $this->service->bulkUnhide($request->validated()['master_names']);

        return $this->successResponse($result, "{$result['unhidden']} produk di-unhide");
    }

    /** Bungkus pelanggaran aturan bisnis (DomainException) menjadi 422. */
    private function guard(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (DomainException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }
}
