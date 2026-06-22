<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Channel\Services\MarketplaceCancelReasonService;
use Modules\Channel\Services\TikTokOrderService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Marketplace', description: 'Cross-marketplace endpoints')]
class MarketplaceCancelReasonController extends Controller
{
    public function __construct(
        private readonly MarketplaceCancelReasonService $service,
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
        summary: 'Get cancellation reasons for a specific marketplace',
        security: [['bearerAuth' => []]],
        tags: ['Marketplace'],
        parameters: [
            new OA\Parameter(name: 'marketplace', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['tiktok', 'lazada', 'shopee'])),
            new OA\Parameter(name: 'shop_id', in: 'query', required: false, description: 'Fetch live reasons from the marketplace for this shop (tiktok/lazada)', schema: new OA\Schema(type: 'string')),
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

        $shopId = $request->query('shop_id');

        if ($shopId && $this->service->isLiveCapable($mp)) {
            try {
                $raw = $mp === MarketplaceCancelReasonService::LAZADA
                    ? app(LazadaOrderService::class)->getCancelReasons($shopId)
                    : app(TikTokOrderService::class)->getCancelReasonsLive($shopId);

                $reasons = $this->service->normalize($raw);

                if (! empty($reasons)) {
                    return $this->successResponse(
                        $reasons,
                        "Daftar alasan pembatalan {$mp} (live)",
                        200,
                        ['source' => 'live', 'marketplace' => $mp]
                    );
                }
            } catch (\Throwable $e) {
                Log::warning("Live cancel reasons fetch failed for {$mp}: {$e->getMessage()}");
            }
        }

        return $this->successResponse(
            $this->service->for($mp),
            "Daftar alasan pembatalan {$mp}",
            200,
            ['source' => 'default', 'marketplace' => $mp]
        );
    }
}
