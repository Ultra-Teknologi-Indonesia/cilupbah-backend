<?php

namespace Modules\Outbound\Http\Controllers;

use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Inventory\Models\Inventory;
use Modules\Outbound\Services\PicklistService;
use Modules\Outbound\Http\Requests\CreatePicklistRequest;
use Modules\Outbound\Http\Requests\PickItemRequest;
use Modules\Report\Services\ReportService;
use OpenApi\Attributes as OA;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Throwable;

#[OA\Tag(name: 'Outbound - Picklist', description: 'API Endpoints for Picklist management')]
#[OA\Schema(
    schema: 'Picklist',
    title: 'Picklist Schema',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
        new OA\Property(property: 'picklist_no', type: 'string', example: 'PK-20260608-0001'),
        new OA\Property(property: 'location_id', type: 'string'),
        new OA\Property(property: 'picker_id', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['DRAFT', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED']),
        new OA\Property(property: 'started_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'created_by', type: 'string'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
class PicklistController extends Controller
{
    public function __construct(
        protected PicklistService $picklistService,
        protected ReportService $reportService,
    ) {}

    #[OA\Get(
        path: '/api/v1/outbound/picklists',
        summary: 'Get paginated picklists',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[status]', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['DRAFT', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'])),
            new OA\Parameter(name: 'filter[location_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[picker_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[q]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $data = $this->picklistService->getAllPaginated($limit);

        return $this->successPaginatedResponse($data);
    }

    #[OA\Post(
        path: '/api/v1/outbound/picklists',
        summary: 'Create a new picklist',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['order_ids', 'location_id'],
                properties: [
                    new OA\Property(property: 'order_ids', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'location_id', type: 'string'),
                    new OA\Property(property: 'picker_id', type: 'string', nullable: true),
                    new OA\Property(property: 'notes', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Created'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function store(CreatePicklistRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_by'] = auth()->user()->email;

        $picklist = $this->picklistService->create($data);

        return $this->successResponse($picklist, null, 201);
    }

    #[OA\Get(
        path: '/api/v1/outbound/picklists/{id}',
        summary: 'Get picklist detail',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 404, description: 'Not Found'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $picklist = $this->picklistService->getById($id);

        if (!$picklist) {
            return $this->errorResponse('Picklist tidak ditemukan.', 404);
        }

        return $this->successResponse($picklist);
    }

    #[OA\Get(
        path: '/api/v1/outbound/picklists/{id}/items',
        summary: 'Get picklist items',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function items(string $id, Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $data = $this->picklistService->getItems($id, $limit);

        return $this->successPaginatedResponse($data);
    }

    #[OA\Get(
        path: '/api/v1/outbound/picklists/{id}/pdf',
        summary: 'Cetak dokumen Picklist sebagai PDF (A4 portrait)',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'PDF stream',
                content: new OA\MediaType(mediaType: 'application/pdf'),
            ),
            new OA\Response(response: 404, description: 'Picklist tidak ditemukan'),
        ]
    )]
    public function pdf(string $id)
    {
        validator(['id' => $id], ['id' => 'required|string|exists:picklists,id'])->validate();

        try {
            $report = $this->reportService->pickListReport(['picklist_id' => $id]);
            $picklist = $report['data'] ?? null;

            if (!$picklist) {
                return $this->errorResponse('Picklist tidak ditemukan.', 404);
            }

            $picklistNo = $picklist->picklist_no ?? 'PICK';
            $filename = "PICK-{$picklistNo}.pdf";

            $filename = str_starts_with((string) $picklistNo, 'PK-')
                ? "{$picklistNo}.pdf"
                : "PICK-{$picklistNo}.pdf";

            $this->attachRecommendedBins($picklist);

            $qrDataUri = $this->generateQrDataUri((string) $picklistNo);

            $pdf = Pdf::loadView('outbound::pdf.picklist', [
                'picklist' => $picklist,
                'qrDataUri' => $qrDataUri,
            ])->setPaper('a4', 'portrait');

            return $pdf->stream($filename);
        } catch (Throwable $e) {
            report($e);
            return $this->errorResponse('Gagal membuat PDF picklist: ' . $e->getMessage(), 500);
        }
    }

    #[OA\Post(
        path: '/api/v1/outbound/picklists/{id}/assign-picker',
        summary: 'Assign picker to picklist',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['picker_id'],
                properties: [
                    new OA\Property(property: 'picker_id', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function assignPicker(string $id, Request $request): JsonResponse
    {
        $request->validate(['picker_id' => 'required|string|exists:users,id']);

        $picklist = $this->picklistService->assignPicker($id, $request->picker_id, auth()->user()->email);

        return $this->successResponse($picklist);
    }

    #[OA\Post(
        path: '/api/v1/outbound/picklists/{id}/start',
        summary: 'Start picking process',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function start(string $id): JsonResponse
    {
        $picklist = $this->picklistService->start($id);

        return $this->successResponse($picklist);
    }

    #[OA\Post(
        path: '/api/v1/outbound/picklists/{id}/items/{itemId}/pick',
        summary: 'Record pick for an item',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'itemId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['qty_picked', 'bin_code'],
                properties: [
                    new OA\Property(property: 'qty_picked', type: 'integer', minimum: 0),
                    new OA\Property(property: 'bin_code', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function pickItem(string $id, string $itemId, PickItemRequest $request): JsonResponse
    {
        try {
            $this->picklistService->pickItem($id, $itemId, $request->validated());
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        return $this->successResponse(null, 'Item berhasil di-pick.');
    }

    #[OA\Post(
        path: '/api/v1/outbound/picklists/{id}/complete',
        summary: 'Complete picklist',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function complete(string $id): JsonResponse
    {
        $picklist = $this->picklistService->complete($id);

        return $this->successResponse($picklist);
    }

    #[OA\Post(
        path: '/api/v1/outbound/picklists/{id}/fail',
        summary: 'Mark picklist as failed',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'reason', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function fail(string $id, Request $request): JsonResponse
    {
        $picklist = $this->picklistService->failPick($id, $request->reason);

        return $this->successResponse($picklist);
    }

    #[OA\Post(
        path: '/api/v1/outbound/picklists/{id}/cancel',
        summary: 'Cancel picklist',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function cancel(string $id): JsonResponse
    {
        $picklist = $this->picklistService->cancel($id);

        return $this->successResponse($picklist);
    }

    #[OA\Delete(
        path: '/api/v1/outbound/picklists/{id}',
        summary: 'Delete draft picklist',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $this->picklistService->delete($id);

        return $this->successResponse(null, 'Picklist berhasil dihapus.');
    }

    protected function attachRecommendedBins($picklist): void
    {
        $items = $picklist->items ?? collect();
        if ($items->isEmpty()) {
            return;
        }

        $locationId = $picklist->location_id;
        $itemIds = $items->pluck('item_id')->filter()->unique()->values()->all();

        $stocks = Inventory::query()
            ->whereIn('item_id', $itemIds)
            ->where('location_id', $locationId)
            ->where('on_hand', '>', 0)
            ->whereNotNull('bin_id')
            ->with('bin:id,bin_final_code')
            ->orderByDesc('on_hand')
            ->get(['id', 'item_id', 'bin_id', 'on_hand']);

        $byItem = $stocks->groupBy('item_id');

        foreach ($items as $item) {
            $top = $byItem->get($item->item_id)?->first();
            $item->recommended_bin_code = optional($top?->bin)->bin_final_code;
        }
    }

    protected function generateQrDataUri(string $content): ?string
    {
        try {
            $svg = QrCode::format('svg')
                ->size(160)
                ->margin(0)
                ->errorCorrection('M')
                ->generate($content);

            return 'data:image/svg+xml;base64,' . base64_encode((string) $svg);
        } catch (Throwable $e) {
            report($e);
            return null;
        }
    }
}
