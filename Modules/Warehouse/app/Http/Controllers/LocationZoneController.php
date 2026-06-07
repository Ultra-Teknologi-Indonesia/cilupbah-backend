<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Warehouse\Repositories\LocationZoneRepository;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Location Zones', description: 'API Endpoints for Warehouse Zones')]
#[OA\Schema(
    schema: 'LocationZone',
    title: 'Location Zone Schema',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '018f6b...'),
        new OA\Property(property: 'location_id', type: 'string', example: '018f6b...'),
        new OA\Property(property: 'zone_code', type: 'string', example: 'Z-A'),
        new OA\Property(property: 'zone_name', type: 'string', example: 'Zona Makanan', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'bins', type: 'array', items: new OA\Items(ref: '#/components/schemas/LocationBin')),
    ]
)]
class LocationZoneController extends Controller
{
    public function __construct(
        protected LocationZoneRepository $zoneRepository
    ) {}

    #[OA\Get(
        path: '/api/v1/locations/{locationId}/zones',
        summary: 'Get list of zones for a location',
        security: [['bearerAuth' => []]],
        tags: ['Location Zones'],
        parameters: [
            new OA\Parameter(name: 'locationId', in: 'path', required: true, description: 'ID of the location', schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/LocationZone')),
                        new OA\Property(property: 'message', type: 'string', example: 'Daftar zona berhasil diambil')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated')
        ]
    )]
    public function index(string $locationId): JsonResponse
    {
        $zones = $this->zoneRepository->findByLocation($locationId);

        return $this->successResponse($zones, 'Daftar zona berhasil diambil');
    }
}
