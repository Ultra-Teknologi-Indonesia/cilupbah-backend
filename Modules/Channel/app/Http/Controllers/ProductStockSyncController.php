<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Channel\Services\ManualStockSyncService;
use OpenApi\Attributes as OA;

class ProductStockSyncController extends Controller
{
    use ApiResponse;

    public function __construct(protected ManualStockSyncService $service)
    {
    }

    #[OA\Post(
        path: "/api/v1/products/sync-stock",
        summary: "Trigger manual sinkronisasi STOK-SAJA ke channel (single/bulk/all)",
        description: "single/bulk: antrekan sync stok untuk product_ids (opsional dibatasi channel_shop_id). all: fan-out di latar belakang via job orchestrator (opsional filters).",
        tags: ["Channel Sync"]
    )]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: "mode", type: "string", enum: ["single", "bulk", "all"]),
        new OA\Property(property: "product_ids", type: "array", items: new OA\Items(type: "string")),
        new OA\Property(property: "channel_shop_id", type: "string", nullable: true),
        new OA\Property(property: "filters", type: "object", nullable: true),
    ]))]
    #[OA\Response(response: 202, description: "Sinkronisasi stok diantrekan / diproses di latar belakang")]
    #[OA\Response(response: 422, description: "Input tidak valid")]
    public function syncStock(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => 'required|in:single,bulk,all',
            'product_ids' => 'required_if:mode,single,bulk|array|min:1',
            'product_ids.*' => 'string',
            'channel_shop_id' => 'nullable|string',
            'filters' => 'nullable|array',
            'filters.channel_shop_id' => 'nullable|string',
            'filters.channel_shop_ids' => 'nullable|array',
            'filters.channel_shop_ids.*' => 'string',
            'filters.product_ids' => 'nullable|array',
            'filters.product_ids.*' => 'string',
        ]);

        if ($data['mode'] === 'single' && count($data['product_ids'] ?? []) !== 1) {
            return $this->errorResponse(
                'Mode single hanya menerima tepat satu product_id.',
                422,
                ['detail' => 'Kirim tepat satu id pada product_ids untuk mode single.'],
                'Input tidak valid',
            );
        }

        if ($data['mode'] === 'all') {
            try {
                $this->service->queueAll($data['filters'] ?? []);
            } catch (\Throwable $e) {
                return $this->errorResponse(
                    'Gagal memulai sinkronisasi stok massal.',
                    500,
                    ['detail' => $e->getMessage()],
                    'Aksi tidak dapat diproses',
                );
            }

            return $this->successResponse(
                ['mode' => 'all', 'filters' => $data['filters'] ?? []],
                'Sinkronisasi stok semua produk sedang diproses di latar belakang.',
                202,
            );
        }

        try {
            $result = $this->service->syncProducts(
                $data['product_ids'],
                $data['channel_shop_id'] ?? null,
            );
        } catch (\Throwable $e) {
            return $this->errorResponse(
                'Gagal mengantrekan sinkronisasi stok.',
                500,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }

        return $this->successResponse(
            $result,
            "Sinkronisasi stok diantrekan ({$result['queued']} listing).",
            202,
        );
    }
}
