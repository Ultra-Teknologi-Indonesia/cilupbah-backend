<?php

namespace Modules\Outbound\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Outbound\Services\PreManifestCancelService;
use Modules\Sales\Exports\CancelledOrdersExport;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Outbound - Pre-Manifest Cancel', description: 'Visibilitas & rekonsiliasi cancel pasca-packing di layar Shipping')]
class PreManifestCancelController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected PreManifestCancelService $service,
    ) {}

    #[OA\Get(
        path: '/api/v1/outbound/pre-manifest/cancelled/count',
        summary: 'Jumlah order cancel pasca-packing yang menunggu dipisahkan',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Pre-Manifest Cancel'],
        responses: [new OA\Response(response: 200, description: 'Success')]
    )]
    public function count(): JsonResponse
    {
        return $this->successResponse(['count' => $this->service->count()]);
    }

    #[OA\Patch(
        path: '/api/v1/outbound/pre-manifest/cancelled/{orderId}/dismiss',
        summary: 'Tandai order cancel pasca-packing sudah dipisahkan dari tumpukan paket',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Pre-Manifest Cancel'],
        parameters: [
            new OA\Parameter(name: 'orderId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 404, description: 'Not Found'),
            new OA\Response(response: 422, description: 'Order bukan cancel pasca-packing'),
        ]
    )]
    public function dismiss(string $orderId): JsonResponse
    {
        try {
            $order = $this->service->dismiss(
                $orderId,
                (string) (auth()->user()->email ?? 'system'),
            );
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        return $this->successResponse($order, 'Order ditandai sudah dipisahkan.');
    }

    #[OA\Patch(
        path: '/api/v1/outbound/pre-manifest/cancelled/{orderId}/undismiss',
        summary: 'Undo dismiss (row muncul kembali di layar Shipping)',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Pre-Manifest Cancel'],
        parameters: [
            new OA\Parameter(name: 'orderId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 404, description: 'Not Found'),
        ]
    )]
    public function undismiss(string $orderId): JsonResponse
    {
        try {
            $order = $this->service->undismiss($orderId);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        return $this->successResponse($order, 'Undo dismiss berhasil.');
    }

    #[OA\Get(
        path: '/api/v1/outbound/pre-manifest/cancelled/export',
        summary: 'Unduh XLSX cancel pasca-packing (default: hari ini)',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Pre-Manifest Cancel'],
        parameters: [
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'source', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'XLSX file stream', content: new OA\MediaType(mediaType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')),
        ]
    )]
    public function export(Request $request)
    {
        $validated = $request->validate([
            'date_from' => 'nullable|date',
            'date_to'   => 'nullable|date|after_or_equal:date_from',
            'source'    => 'nullable|string|max:50',
        ]);

        $today = now()->toDateString();
        $dateFrom = $validated['date_from'] ?? $today;
        $dateTo   = $validated['date_to']   ?? $today;

        $export = new CancelledOrdersExport(
            dateFrom:     $dateFrom,
            dateTo:       $dateTo,
            postPackOnly: true,
            source:       $validated['source'] ?? null,
        );

        $filename = sprintf('cancel-pasca-packing-%s-%s.xlsx', $dateFrom, $dateTo);

        return Excel::download($export, $filename);
    }
}
