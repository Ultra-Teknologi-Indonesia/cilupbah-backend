<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Product\Models\ProductVariant;
use Modules\Sales\Exceptions\InsufficientStockException;
use Modules\Sales\Http\Requests\StoreSalesOrderManualRequest;
use Modules\Sales\Http\Resources\SalesOrderResource;
use Modules\Sales\Services\SalesOrderManualService;

class SalesOrderManualController extends Controller
{
    use ApiResponse;

    public function __construct(private SalesOrderManualService $service)
    {
    }

    public function store(StoreSalesOrderManualRequest $request): JsonResponse
    {
        try {
            $order = $this->service->create($request->validated());
        } catch (InsufficientStockException $e) {
            return $this->errorResponse(
                'Stok tidak mencukupi untuk salah satu produk.',
                422,
                [
                    'sku'       => $e->getSku(),
                    'available' => $e->getAvailable(),
                    'requested' => $e->getRequested(),
                ],
            );
        }

        return $this->successResponse(
            new SalesOrderResource($order->load(['items', 'internalStore', 'salesman', 'location'])),
            'Pesanan manual berhasil dibuat',
            201
        );
    }

    public function lookupSku(Request $request, InventoryRepository $inventory): JsonResponse
    {
        $request->validate([
            'sku'         => ['required', 'string', 'max:64'],
            'location_id' => ['required', 'uuid', 'exists:locations,id'],
        ]);

        $sku = $request->query('sku');
        $locationId = $request->query('location_id');

        $variant = ProductVariant::with('product:id,name')
            ->where('sku', $sku)
            ->where('is_active', true)
            ->first();

        if (!$variant) {
            return $this->errorResponse("SKU '{$sku}' tidak ditemukan.", 404);
        }

        $onHand   = $inventory->sumOnHandAtLocation($variant->id, $locationId);
        $reserved = $inventory->sumReservedAtLocation($variant->id, $locationId);
        $available = max(0, ((int) $onHand) - ((int) $reserved));

        return $this->successResponse([
            'item_id'     => $variant->id,
            'sku'         => $variant->sku,
            'barcode'     => $variant->barcode,
            'name'        => $variant->product?->name,
            'sell_price'  => (float) $variant->sell_price,
            'weight_gram' => (int) round(((float) $variant->weight) * 1000),
            'on_hand'     => (int) $onHand,
            'reserved'    => (int) $reserved,
            'available'   => $available,
        ], 'SKU ditemukan');
    }
}
