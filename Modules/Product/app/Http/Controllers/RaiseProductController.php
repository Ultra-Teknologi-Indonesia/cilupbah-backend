<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Product\Http\Resources\RaiseProductDetailResource;
use Modules\Product\Http\Resources\RaiseProductResource;
use Modules\Product\Models\RaiseProduct;
use Modules\Product\Services\RaiseProductService;

class RaiseProductController extends Controller
{
    use ApiResponse;

    public function __construct(private RaiseProductService $service) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => 'nullable|string',
            'per_page' => 'nullable|integer|min:1|max:500',
            'page' => 'nullable|integer|min:1',
        ]);

        $paginator = $this->service->paginate();

        $paginator->setCollection(
            $paginator->getCollection()->map(
                fn (RaiseProduct $raiseProduct) => (new RaiseProductResource($raiseProduct))->resolve($request)
            )
        );

        return $this->successPaginatedResponse($paginator, 'Get raise products success');
    }

    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $header = $this->service->find($id);
        } catch (ModelNotFoundException) {
            return $this->errorResponse('Data naikkan produk tidak ditemukan', 404);
        }

        $details = $this->service->paginateDetails($id);

        $items = $details->getCollection()
            ->map(fn ($detail) => (new RaiseProductDetailResource($detail))->resolve($request))
            ->values()
            ->all();

        $data = (new RaiseProductResource($header))->resolve($request);
        $data['details'] = $items;

        return $this->successResponse($data, 'Get raise product detail success', 200, [
            'current_page' => $details->currentPage(),
            'last_page' => $details->lastPage(),
            'per_page' => $details->perPage(),
            'total' => $details->total(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'shop_id' => 'required|string',
        ]);

        try {
            $raiseProduct = $this->service->create($validated['shop_id'], $request->user()?->id);
        } catch (DomainException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        return $this->successResponse(
            (new RaiseProductResource($raiseProduct))->resolve($request),
            'Data naikkan produk berhasil dibuat',
            201
        );
    }

    public function addProduct(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'product_channel_mapping_id' => 'required|uuid',
        ]);

        try {
            $detail = $this->service->addProduct($id, $validated['product_channel_mapping_id']);
        } catch (ModelNotFoundException) {
            return $this->errorResponse('Data naikkan produk tidak ditemukan', 404);
        } catch (DomainException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        return $this->successResponse(
            (new RaiseProductDetailResource($detail))->resolve($request),
            'Produk ditambahkan ke data naikkan',
            201
        );
    }

    public function updateProduct(Request $request, string $id, string $detailId): JsonResponse
    {
        $validated = $request->validate([
            'is_active' => 'sometimes|boolean',
            'is_repeatable' => 'sometimes|boolean',
        ]);

        try {
            $detail = $this->service->updateProduct($id, $detailId, $validated);
        } catch (ModelNotFoundException) {
            return $this->errorResponse('Produk pada data naikkan tidak ditemukan', 404);
        }

        return $this->successResponse(
            (new RaiseProductDetailResource($detail))->resolve($request),
            'Produk pada data naikkan diperbarui'
        );
    }

    public function removeProduct(string $id, string $detailId): JsonResponse
    {
        try {
            $this->service->removeProduct($id, $detailId);
        } catch (ModelNotFoundException) {
            return $this->errorResponse('Produk pada data naikkan tidak ditemukan', 404);
        }

        return $this->successResponse(null, 'Produk dilepas dari data naikkan');
    }

    public function history(Request $request, string $id): JsonResponse
    {
        try {
            $this->service->find($id);
        } catch (ModelNotFoundException) {
            return $this->errorResponse('Data naikkan produk tidak ditemukan', 404);
        }

        $history = $this->service->paginateHistory($id);

        $items = $history->getCollection()
            ->map(fn ($detail) => (new RaiseProductDetailResource($detail))->resolve($request))
            ->values()
            ->all();

        return $this->successPaginatedResponse(
            $history->setCollection(collect($items)),
            'Get raise product history success'
        );
    }

    public function raise(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'detail_ids' => 'sometimes|array',
            'detail_ids.*' => 'uuid',
        ]);

        try {
            $raiseProduct = $this->service->raise($id, $validated['detail_ids'] ?? null);
        } catch (ModelNotFoundException) {
            return $this->errorResponse('Data naikkan produk tidak ditemukan', 404);
        }

        return $this->successResponse(
            (new RaiseProductResource($raiseProduct))->resolve($request),
            'Produk diantrekan untuk dinaikkan',
            202
        );
    }

    public function destroy(string $id): JsonResponse
    {
        try {
            $this->service->delete($id);
        } catch (ModelNotFoundException) {
            return $this->errorResponse('Data naikkan produk tidak ditemukan', 404);
        }

        return $this->successResponse(null, 'Data naikkan produk dihapus');
    }
}
