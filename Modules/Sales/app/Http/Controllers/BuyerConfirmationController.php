<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\Sales\Http\Requests\DecideBuyerConfirmationRequest;
use Modules\Sales\Http\Resources\OrderBuyerConfirmationResource;
use Modules\Sales\Repositories\BuyerConfirmationRepository;
use Modules\Sales\Services\BuyerConfirmationService;
use OpenApi\Attributes as OA;

class BuyerConfirmationController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected BuyerConfirmationService $service,
        protected BuyerConfirmationRepository $repository,
    ) {}

    #[OA\Get(
        path: '/api/v1/sales/buyer-confirmations',
        summary: 'Daftar pesanan yang menunggu konfirmasi pembeli atau menunggu stok',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        parameters: [
            new OA\Parameter(name: 'state', in: 'query', schema: new OA\Schema(type: 'string', enum: ['awaiting', 'waiting-stock'])),
        ],
        responses: [new OA\Response(response: 200, description: 'Daftar konfirmasi pembeli')]
    )]
    public function index(Request $request)
    {
        $paginator = $this->repository->paginate((string) $request->query('state', 'awaiting'));

        $paginator->getCollection()->transform(
            fn ($confirmation) => new OrderBuyerConfirmationResource($confirmation),
        );

        return $this->successPaginatedResponse($paginator);
    }

    #[OA\Get(
        path: '/api/v1/sales/orders/{id}/buyer-confirmations',
        summary: 'Riwayat konfirmasi pembeli untuk satu pesanan',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        responses: [new OA\Response(response: 200, description: 'Riwayat konfirmasi pembeli')]
    )]
    public function forOrder(string $id)
    {
        return $this->successResponse(
            OrderBuyerConfirmationResource::collection($this->repository->forOrder($id)),
            'Riwayat konfirmasi pembeli berhasil diambil',
        );
    }

    #[OA\Post(
        path: '/api/v1/sales/buyer-confirmations/{id}/decide',
        summary: 'Catat keputusan pembeli atas SKU yang stoknya kosong',
        security: [['bearerAuth' => []]],
        tags: ['Sales Orders'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['outcome'],
            properties: [
                new OA\Property(property: 'outcome', type: 'string', enum: ['CANCEL', 'REPLACE', 'REMOVE', 'WAIT']),
                new OA\Property(property: 'replacement_sku', type: 'string'),
                new OA\Property(property: 'note', type: 'string'),
            ]
        )),
        responses: [new OA\Response(response: 200, description: 'Keputusan pembeli tersimpan')]
    )]
    public function decide(DecideBuyerConfirmationRequest $request, string $id)
    {
        $payload = $request->validated();

        $confirmation = $this->service->decide(
            $id,
            $payload['outcome'],
            $payload['replacement_sku'] ?? null,
            $payload['note'] ?? null,
        );

        return $this->successResponse(
            new OrderBuyerConfirmationResource($confirmation),
            'Keputusan pembeli tersimpan',
        );
    }
}
