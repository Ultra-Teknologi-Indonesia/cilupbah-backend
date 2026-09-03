<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Channel\Services\MarketplaceCancelReasonService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Marketplace', description: 'Cross-marketplace endpoints')]
class MarketplaceCancelReasonController extends Controller
{
    public function __construct(
        private readonly MarketplaceCancelReasonService $service,
        private readonly LazadaOrderService $lazadaOrderService,
    ) {}

    #[OA\Get(
        path: '/api/v1/marketplace/cancel-reasons',
        summary: 'Get cancellation reasons for all marketplaces',
        security: [['bearerAuth' => []]],
        tags: ['Marketplace'],
        responses: [new OA\Response(response: 200, description: 'OK')]
    )]
    public function index(): JsonResponse
    {
        return $this->successResponse(
            $this->service->all(),
            'Daftar alasan pembatalan per marketplace'
        );
    }

    #[OA\Get(
        path: '/api/v1/marketplace/cancel-reasons/{marketplace}',
        summary: 'Get seller-initiated cancellation reasons for a specific marketplace',
        security: [['bearerAuth' => []]],
        tags: ['Marketplace'],
        parameters: [
            new OA\Parameter(name: 'marketplace', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['tiktok', 'lazada', 'shopee'])),
            new OA\Parameter(name: 'shop_id', in: 'query', required: false, description: 'Wajib untuk lazada — ambil reason_id numerik live', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'TikTok: status channel MENTAH (UNPAID / ON_HOLD / AWAITING_SHIPMENT) untuk memilih set alasan', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK'),
            new OA\Response(response: 422, description: 'Unsupported marketplace'),
        ]
    )]
    public function show(string $marketplace, Request $request): JsonResponse
    {
        $mp = strtolower($marketplace);

        if (! $this->service->supports($mp)) {
            return $this->errorResponse(
                "Marketplace '{$marketplace}' tidak didukung. Pilihan: " . implode(', ', MarketplaceCancelReasonService::SUPPORTED),
                422
            );
        }

        if ($mp === MarketplaceCancelReasonService::LAZADA) {
            $shopId = $request->query('shop_id');

            if (! $shopId) {
                return $this->errorResponse('Parameter shop_id wajib untuk alasan pembatalan Lazada.', 422);
            }

            try {
                $raw = $this->lazadaOrderService->getCancelReasons($shopId);
                $reasons = $this->service->normalize($raw);

                return $this->successResponse(
                    $reasons,
                    'Daftar alasan pembatalan lazada (live)',
                    200,
                    ['source' => 'live', 'marketplace' => $mp]
                );
            } catch (\Throwable $e) {
                Log::warning("Live cancel reasons fetch failed for lazada: {$e->getMessage()}");

                return $this->errorResponse('Gagal memuat alasan pembatalan Lazada. Coba lagi.', 502);
            }
        }

        $context = $mp === MarketplaceCancelReasonService::TIKTOK
            ? $request->query('status')
            : null;

        return $this->successResponse(
            $this->service->for($mp, $context),
            "Daftar alasan pembatalan {$mp}",
            200,
            ['source' => 'default', 'marketplace' => $mp]
        );
    }
}
