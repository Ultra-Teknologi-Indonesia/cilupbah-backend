<?php

namespace Modules\Outbound\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Outbound - WMS', description: 'API Endpoints for WMS utilities (employee, default bin)')]
class WmsController extends Controller
{
    use ApiResponse;

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
        $user = User::where('email', $identifier)
            ->orWhere('nik', $identifier)
            ->first();

        if (!$user) {
            return $this->errorResponse('Employee tidak ditemukan.', 404);
        }

        return $this->successResponse($user->only(['id', 'name', 'email', 'nik']));
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
        $location = Location::find($locationId);

        if (!$location) {
            return $this->errorResponse('Location tidak ditemukan.', 404);
        }

        $bin = null;
        if ($location->default_bin_id) {
            $bin = LocationBin::find($location->default_bin_id);
        }

        return $this->successResponse([
            'location_id' => $location->id,
            'location_name' => $location->location_name,
            'default_bin' => $bin,
        ]);
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

        $location = Location::find($locationId);

        if (!$location) {
            return $this->errorResponse('Location tidak ditemukan.', 404);
        }

        $bin = LocationBin::where('id', $request->bin_id)
            ->where('location_id', $locationId)
            ->first();

        if (!$bin) {
            return $this->errorResponse('Bin tidak ditemukan di location ini.', 422);
        }

        $location->update(['default_bin_id' => $request->bin_id]);

        return $this->successResponse([
            'location_id' => $location->id,
            'location_name' => $location->location_name,
            'default_bin' => $bin,
        ]);
    }
}
