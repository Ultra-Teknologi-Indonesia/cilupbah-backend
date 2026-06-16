<?php

namespace Modules\Channel\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Channel\Services\MarketplaceCancelReasonService;
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
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK'),
            new OA\Response(response: 422, description: 'Unsupported marketplace'),
        ]
    )]
    public function show(string $marketplace): JsonResponse
    {
        if (! $this->service->supports($marketplace)) {
            return $this->errorResponse(
                "Marketplace '{$marketplace}' tidak didukung. Pilihan: " . implode(', ', MarketplaceCancelReasonService::SUPPORTED),
                422
            );
        }

        return $this->successResponse(
            $this->service->for($marketplace),
            "Daftar alasan pembatalan {$marketplace}"
        );
    }
}
