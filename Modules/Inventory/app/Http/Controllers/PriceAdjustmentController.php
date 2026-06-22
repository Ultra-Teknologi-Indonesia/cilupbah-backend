<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Services\PriceAdjustmentService;

class PriceAdjustmentController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PriceAdjustmentService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->successPaginatedResponse(
            $this->service->list($request->all())
        );
    }

    public function show(string $id): JsonResponse
    {
        $adjustment = $this->service->show($id);

        if (! $adjustment) {
            return $this->errorResponse('Penyesuaian harga tidak ditemukan.', 404);
        }

        return $this->successResponse($adjustment);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'adjustment_date' => 'required|date',
            'type' => 'required|in:online,offline',
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.variant_id' => 'required|uuid|exists:product_variants,id',
            'items.*.channel_shop_id' => 'nullable|uuid|exists:channel_shops,id',
            'items.*.location_id' => 'nullable|uuid|exists:locations,id',
            'items.*.new_price' => 'required|numeric|min:0',
        ]);

        $createdBy = $request->user()->name ?? $request->user()->email;

        $adjustment = $this->service->store($request->all(), $createdBy);

        return $this->successResponse($adjustment, 'Penyesuaian harga berhasil dibuat.', 201);
    }

    public function apply(string $id, Request $request): JsonResponse
    {
        try {
            $appliedBy = $request->user()->name ?? $request->user()->email;
            $adjustment = $this->service->apply($id, $appliedBy);

            return $this->successResponse($adjustment, 'Penyesuaian harga berhasil diterapkan.');
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    public function cancel(string $id): JsonResponse
    {
        try {
            $adjustment = $this->service->cancel($id);

            return $this->successResponse($adjustment, 'Penyesuaian harga berhasil dibatalkan.');
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    public function destroy(string $id): JsonResponse
    {
        try {
            $this->service->destroy($id);

            return $this->successResponse(null, 'Penyesuaian harga berhasil dihapus.');
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }
}
