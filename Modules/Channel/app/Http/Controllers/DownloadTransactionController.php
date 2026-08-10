<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Channel\Http\Resources\DownloadFailuresResource;
use Modules\Channel\Http\Resources\DownloadTransactionResource;
use Modules\Channel\Models\DownloadTransaction;
use Modules\Channel\Repositories\DownloadTransactionRepository;
use Modules\Channel\Services\DownloadFailureService;

class DownloadTransactionController extends Controller
{
    use ApiResponse;

    public function __construct(private DownloadTransactionRepository $repository) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:500',
            'page' => 'nullable|integer|min:1',
        ]);

        $paginator = $this->repository->paginate();

        $paginator->setCollection(
            $paginator->getCollection()->map(
                fn (DownloadTransaction $trx) => (new DownloadTransactionResource($trx))->resolve($request)
            )
        );

        return $this->successPaginatedResponse($paginator, 'Get download transactions success');
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:500',
            'page' => 'nullable|integer|min:1',
        ]);

        try {
            $transaction = $this->repository->find($id);
        } catch (ModelNotFoundException) {
            return $this->errorResponse('Transaksi download tidak ditemukan', 404);
        }

        $products = $this->repository->paginateShopProducts($transaction->channel_shop_id);

        $items = $products->getCollection()->map(function ($product) {
            $variant = $product->relationLoaded('variants') ? $product->variants->first() : null;
            $mapping = $product->relationLoaded('channelMappings') ? $product->channelMappings->first() : null;
            $isMaster = $product->status === \Modules\Product\Models\Product::STATUS_MASTER;
            $thumbnail = $product->relationLoaded('media')
                ? optional($product->media->firstWhere('is_primary', true) ?? $product->media->first())->url
                : null;

            return [
                'item_id' => $product->id,
                'item_name' => $product->name,
                'item_code' => $variant->sku ?? null,
                'img_url' => $thumbnail,
                'channel_group_id' => $mapping->external_product_id ?? null,
                'status' => $product->status,
                'is_master' => $isMaster,
                'master_item_name' => $product->name,
            ];
        })->values()->all();

        return $this->successResponse([
            'transaction' => (new DownloadTransactionResource($transaction))->resolve($request),
            'products' => $items,
            'count' => $products->total(),
            'percent' => $transaction->progress_percent,
            'state' => $transaction->state,
        ], 'Get download transaction detail success', 200, [
            'current_page' => $products->currentPage(),
            'last_page' => $products->lastPage(),
            'per_page' => $products->perPage(),
            'total' => $products->total(),
        ]);
    }

    public function failures(Request $request, string $id, DownloadFailureService $service): JsonResponse
    {
        try {
            $transaction = $this->repository->find($id);
        } catch (ModelNotFoundException) {
            return $this->errorResponse('Transaksi download tidak ditemukan', 404);
        }

        return $this->successResponse(
            (new DownloadFailuresResource($service->report($transaction)))->resolve($request),
            'Get download failures success',
        );
    }
}
