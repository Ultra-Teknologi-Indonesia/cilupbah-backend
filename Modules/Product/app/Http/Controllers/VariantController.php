<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Modules\Product\Http\Requests\DeleteVariantRequest;
use Modules\Product\Http\Resources\VariantResource;
use Modules\Product\Services\ProductVariantService;

class VariantController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ProductVariantService $service,
    ) {
    }

    /** Jubelio: GET /variations — Ambil semua varian produk. */
    public function index(): JsonResponse
    {
        return $this->successPaginatedResponse(
            VariantResource::collection($this->service->list()),
            'Get variations success'
        );
    }

    /** Jubelio: DELETE /inventory/items/item-variant — Hapus varian item (id via variant_id). */
    public function destroy(DeleteVariantRequest $request): JsonResponse
    {
        $variant = $this->service->find($request->validated()['variant_id']);
        if (! $variant) {
            return $this->errorResponse('Varian tidak ditemukan', 404);
        }

        try {
            $this->service->deleteVariant($variant);
        } catch (DomainException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        return $this->successResponse(null, 'Varian berhasil dihapus');
    }
}
