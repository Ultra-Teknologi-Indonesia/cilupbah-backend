<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use App\Models\Role;
use App\Traits\ApiResponse;
use OpenApi\Attributes as OA;
use Modules\Auth\Services\RoleService;

#[OA\Tag(name: 'Roles', description: 'Role Management Endpoints')]
class RoleController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected RoleService $roleService
    ) {}

    #[OA\Get(
        path: '/api/v1/roles',
        summary: 'Get all available roles',
        security: [['bearerAuth' => []]],
        tags: ['Roles'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of roles retrieved successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'success'),
                        new OA\Property(property: 'message', type: 'string', example: 'Daftar role berhasil dimuat.'),
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'string', example: '018f6b...'),
                                    new OA\Property(property: 'name', type: 'string', example: 'admin')
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
        $roles = $this->roleService->getAllRoles();

        return $this->successResponse($roles, 'Daftar role berhasil dimuat.');
    }
}
