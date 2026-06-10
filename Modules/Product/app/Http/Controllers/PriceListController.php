<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Product\Http\Requests\UpdatePriceListRequest;
use Modules\Product\Http\Resources\PriceListResource;
use Modules\Product\Services\PriceListService;

class PriceListController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PriceListService $service,
    ) {
    }

    /** Jubelio: GET /inventory/internal-price-list — Ambil semua harga produk. */
    public function index(): JsonResponse
    {
        return $this->successPaginatedResponse(
            PriceListResource::collection($this->service->list()),
            'Get internal price list success'
        );
    }

    /** Jubelio: POST /inventory/price-list — Ubah harga produk. */
    public function update(UpdatePriceListRequest $request): JsonResponse
    {
        $this->service->updatePrices($request->validated()['items']);

        return $this->successResponse(null, 'Harga produk berhasil diperbarui');
    }
}
