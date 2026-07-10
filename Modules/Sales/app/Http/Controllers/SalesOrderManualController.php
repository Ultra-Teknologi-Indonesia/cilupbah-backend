<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    public function lookupSku(Request $request): JsonResponse
    {
        $request->validate([
            'sku'         => ['required', 'string', 'max:64'],
            'location_id' => ['required', 'uuid', 'exists:locations,id'],
        ]);

        $sku = $request->query('sku');
        $result = $this->service->lookupSku($sku, $request->query('location_id'));

        if ($result === null) {
            return $this->errorResponse("SKU '{$sku}' tidak ditemukan.", 404);
        }

        return $this->successResponse($result, 'SKU ditemukan');
    }
}
