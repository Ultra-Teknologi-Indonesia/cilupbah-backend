<?php

namespace Modules\Outbound\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Outbound\Services\OutboundAdHocPickService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Outbound - Ad-hoc Pick', description: 'Pick langsung tanpa picklist')]
class AdHocPickController extends Controller
{
    public function __construct(
        protected OutboundAdHocPickService $service,
    ) {}

    #[OA\Post(
        path: '/api/v1/outbound/orders/ad-hoc-pick',
        summary: 'Selesaikan ad-hoc pick: order reserved -> picked',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Ad-hoc Pick'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['order_id'],
                properties: [
                    new OA\Property(property: 'order_id', type: 'string'),
                    new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object'), nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 422, description: 'Validation/State Error'),
        ]
    )]
    public function complete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => 'required|string|exists:sales_orders,id',
            'items' => 'sometimes|array',
            'items.*.order_item_id' => 'required_with:items|string',
            'items.*.qty_picked' => 'required_with:items|integer|min:0',
            'items.*.bin_id' => 'nullable|string',
        ]);

        try {
            $order = $this->service->complete($data['order_id']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $order]);
    }

    #[OA\Post(
        path: '/api/v1/outbound/orders/ad-hoc-pick/scan',
        summary: 'Scan SKU pada ad-hoc pick; auto-complete saat semua item terpenuhi',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Ad-hoc Pick'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['order_id', 'sku'],
                properties: [
                    new OA\Property(property: 'order_id', type: 'string'),
                    new OA\Property(property: 'sku', type: 'string'),
                    new OA\Property(property: 'qty', type: 'integer', default: 1),
                    new OA\Property(property: 'bin_id', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 422, description: 'Validation/State Error'),
        ]
    )]
    public function scan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => 'required|string|exists:sales_orders,id',
            'sku' => 'required|string',
            'qty' => 'sometimes|integer|min:1',
            'bin_id' => 'nullable|string',
        ]);

        try {
            $result = $this->service->scan(
                $data['order_id'],
                $data['sku'],
                (int) ($data['qty'] ?? 1),
            );
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'completed' => $result['completed'],
                'matched_item_id' => $result['matched_item_id'],
                'qty_picked' => $result['qty_picked'],
                'qty_ordered' => $result['qty_ordered'],
                'progress' => $result['progress'],
                'order' => $result['order'],
            ],
        ]);
    }
}
