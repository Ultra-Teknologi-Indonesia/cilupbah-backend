<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use App\Traits\ApiResponse;
use OpenApi\Attributes as OA;
use Modules\Auth\Services\RoleService;
use Modules\Auth\Http\Requests\StoreRoleRequest;
use Modules\Auth\Http\Requests\UpdateRoleRequest;

#[OA\Tag(name: 'Roles', description: 'Role Management Endpoints')]
class RoleController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected RoleService $roleService
    ) {}

    #[OA\Get(
        path: '/api/v1/roles',
        summary: 'Get all roles (Paginated)',
        security: [['bearerAuth' => []]],
        tags: ['Roles'],
        responses: [
            new OA\Response(response: 200, description: 'List of roles retrieved successfully'),
            new OA\Response(response: 401, description: 'Unauthenticated')
        ]
    )]
    public function index(): JsonResponse
    {
        $roles = $this->roleService->getPaginatedRoles();

        return $this->successPaginatedResponse($roles);
    }

    #[OA\Post(
        path: '/api/v1/roles',
        summary: 'Create a new role',
        security: [['bearerAuth' => []]],
        tags: ['Roles'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'security')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Role created successfully'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 403, description: 'Forbidden access')
        ]
    )]
    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = $this->roleService->createRole($request->validated());

        return $this->successResponse($role, 'Role berhasil dibuat.', 201);
    }

    #[OA\Put(
        path: '/api/v1/roles/{id}',
        summary: 'Update an existing role',
        security: [['bearerAuth' => []]],
        tags: ['Roles'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'Role ID',
                schema: new OA\Schema(type: 'string', example: '123...')
            )
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'security_lead')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Role updated successfully'),
            new OA\Response(response: 404, description: 'Role not found'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 403, description: 'Forbidden access')
        ]
    )]
    public function update(UpdateRoleRequest $request, string $id): JsonResponse
    {
        $role = $this->roleService->updateRole($id, $request->validated());

        return $this->successResponse($role, 'Role berhasil diperbarui.');
    }

    #[OA\Delete(
        path: '/api/v1/roles/{id}',
        summary: 'Delete a role',
        security: [['bearerAuth' => []]],
        tags: ['Roles'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'Role ID',
                schema: new OA\Schema(type: 'string', example: '123...')
            )
        ],
        responses: [
            new OA\Response(response: 200, description: 'Role deleted successfully'),
            new OA\Response(response: 404, description: 'Role not found'),
            new OA\Response(response: 403, description: 'Forbidden access')
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $this->roleService->deleteRole($id);

        return $this->successResponse(null, 'Role berhasil dihapus.');
    }
}
