<?php

namespace Modules\Purchase\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Purchase\Services\PurchaseSerialNumberService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Purchase Serial Numbers', description: 'API Endpoints for Purchase Serial/Batch Tracking')]
class PurchaseSerialNumberController extends Controller
{
    public function __construct(
        protected PurchaseSerialNumberService $serialService
    ) {}

    #[OA\Get(
        path: '/api/v1/purchase/serial-number/wms/{bill_detail_id}',
        summary: 'Get serial/batch numbers for a bill item',
        security: [['bearerAuth' => []]],
        tags: ['Purchase Serial Numbers'],
        parameters: [new OA\Parameter(name: 'bill_detail_id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function wmsByBillDetail(string $billDetailId): JsonResponse
    {
        $serials = $this->serialService->getByBillItemId($billDetailId);
        return $this->successResponse($serials, 'Serial numbers berhasil diambil');
    }

    #[OA\Post(
        path: '/api/v1/purchase/serial-number/mark-printed',
        summary: 'Mark serial numbers as printed',
        security: [['bearerAuth' => []]],
        tags: ['Purchase Serial Numbers'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['ids'],
            properties: [
                new OA\Property(property: 'ids', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(property: 'printed_by', type: 'string'),
            ]
        )),
        responses: [new OA\Response(response: 200, description: 'Berhasil ditandai printed')]
    )]
    public function markPrinted(Request $request): JsonResponse
    {
        $count = $this->serialService->markPrinted($request->only('ids', 'printed_by'));
        return $this->successResponse(['updated' => $count], "{$count} serial berhasil ditandai printed");
    }
}
