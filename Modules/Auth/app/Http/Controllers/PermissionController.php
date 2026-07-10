<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use App\Traits\ApiResponse;
use Modules\Auth\Http\Resources\PermissionResource;
use Modules\Auth\Services\PermissionService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Permissions', description: 'Permission Management Endpoints')]
class PermissionController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected PermissionService $permissionService
    ) {}

    #[OA\Get(
        path: '/api/v1/permissions',
        summary: 'Get all available permissions',
        security: [['bearerAuth' => []]],
        tags: ['Permissions'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of permissions retrieved successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'success'),
                        new OA\Property(property: 'message', type: 'string', example: 'Daftar hak akses berhasil dimuat.'),
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
                                    new OA\Property(property: 'name', type: 'string', example: 'create-user')
                                ]
                            )
                        )
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated')
        ]
    )]
    public function index(): JsonResponse
    {
        $permissions = $this->permissionService->getAllPermissions();

        return $this->successResponse(PermissionResource::collection($permissions), 'Daftar hak akses berhasil dimuat.');
    }

    #[OA\Get(
        path: '/api/v1/permissions/catalog',
        summary: 'Get grouped permission catalog for the access-rights matrix',
        security: [['bearerAuth' => []]],
        tags: ['Permissions'],
        responses: [
            new OA\Response(response: 200, description: 'Permission catalog retrieved successfully'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function catalog(): JsonResponse
    {
        return $this->successResponse($this->permissionService->getCatalog(), 'Katalog hak akses berhasil dimuat.');
    }
}
