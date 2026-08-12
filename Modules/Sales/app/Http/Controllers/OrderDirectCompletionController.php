<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Modules\Sales\Http\Requests\CompleteOrdersDirectlyRequest;
use Modules\Sales\Http\Requests\PreviewDirectCompletionRequest;
use Modules\Sales\Services\OrderDirectCompletionService;
use OpenApi\Attributes as OA;

class OrderDirectCompletionController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected OrderDirectCompletionService $service,
    ) {}

    #[OA\Post(
        path: '/api/v1/sales/orders/direct-completion/preview',
        summary: 'Hitung kebutuhan per SKU dan saran rak untuk Selesaikan Langsung',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['order_ids'],
            properties: [
                new OA\Property(property: 'order_ids', type: 'array', items: new OA\Items(type: 'string')),
            ]
        )),
        responses: [new OA\Response(response: 200, description: 'Kebutuhan per SKU beserta rak yang tersedia')]
    )]
    public function preview(PreviewDirectCompletionRequest $request)
    {
        $result = $this->service->preview($request->validated()['order_ids']);

        return $this->successResponse($result, 'Kebutuhan stok berhasil dihitung');
    }

    #[OA\Post(
        path: '/api/v1/sales/orders/direct-completion',
        summary: 'Selesaikan pesanan langsung dengan memotong stok dari rak terpilih',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['order_ids'],
            properties: [
                new OA\Property(property: 'order_ids', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(property: 'allocations', type: 'array', items: new OA\Items(type: 'object')),
            ]
        )),
        responses: [new OA\Response(response: 200, description: 'Ringkasan pesanan yang selesai dan yang tertahan')]
    )]
    public function complete(CompleteOrdersDirectlyRequest $request)
    {
        $payload = $request->validated();
        $result = $this->service->complete($payload['order_ids'], $payload['allocations'] ?? []);

        return $this->successResponse($result, $this->summaryMessage($result));
    }

    private function summaryMessage(array $result): string
    {
        $parts = ["{$result['completed_count']} pesanan selesai"];
        $blocked = count($result['blocked']);

        if ($blocked > 0) {
            $parts[] = "{$blocked} tertahan";
        }

        if ($result['raised_confirmations'] > 0) {
            $parts[] = "{$result['raised_confirmations']} menunggu konfirmasi pembeli";
        }

        return implode(' · ', $parts);
    }
}
