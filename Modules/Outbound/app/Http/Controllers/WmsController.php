<?php

namespace Modules\Outbound\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Outbound\Http\Resources\WmsEmployeeResource;
use Modules\Outbound\Services\WmsService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Outbound - WMS', description: 'API Endpoints for WMS utilities (employee, default bin)')]
class WmsController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected WmsService $wmsService,
    ) {}

    #[OA\Get(
        path: '/api/v1/outbound/wms/employee/{identifier}',
        summary: 'Get WMS employee by NIK or email',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - WMS'],
        parameters: [
            new OA\Parameter(name: 'identifier', in: 'path', required: true, description: 'NIK or Email of employee', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 404, description: 'Not Found'),
        ]
    )]
    public function employee(string $identifier): JsonResponse
    {
        $user = $this->wmsService->findEmployee($identifier);

        if (!$user) {
            return $this->errorResponse('Employee tidak ditemukan.', 404);
        }

        return $this->successResponse(new WmsEmployeeResource($user));
    }

    #[OA\Get(
        path: '/api/v1/outbound/wms/default-bin/{locationId}',
        summary: 'Get default bin for a location',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - WMS'],
        parameters: [
            new OA\Parameter(name: 'locationId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 404, description: 'Not Found'),
        ]
    )]
    public function defaultBin(string $locationId): JsonResponse
    {
        $location = $this->wmsService->findLocation($locationId);

        if (!$location) {
            return $this->errorResponse('Location tidak ditemukan.', 404);
        }

        return $this->successResponse($this->wmsService->defaultBinPayload($location));
    }

    #[OA\Put(
        path: '/api/v1/outbound/wms/default-bin/{locationId}',
        summary: 'Set default bin for a location',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - WMS'],
        parameters: [
            new OA\Parameter(name: 'locationId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['bin_id'],
                properties: [
                    new OA\Property(property: 'bin_id', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 404, description: 'Not Found'),
        ]
    )]
    public function setDefaultBin(string $locationId, Request $request): JsonResponse
    {
        $request->validate(['bin_id' => 'required|string|exists:location_bins,id']);

        $location = $this->wmsService->findLocation($locationId);

        if (!$location) {
            return $this->errorResponse('Location tidak ditemukan.', 404);
        }

        $bin = $this->wmsService->findBinInLocation($request->bin_id, $locationId);

        if (!$bin) {
            return $this->errorResponse('Bin tidak ditemukan di location ini.', 422);
        }

        return $this->successResponse($this->wmsService->setDefaultBin($location, $bin));
    }
}
