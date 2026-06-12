<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Auth\Http\Requests\StoreRoleRequest;
use Modules\Auth\Http\Requests\SyncRolePermissionsRequest;
use Modules\Auth\Http\Requests\UpdateRoleRequest;
use Modules\Auth\Http\Resources\RoleResource;
use Modules\Auth\Services\RoleService;
use OpenApi\Attributes as OA;

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
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(): JsonResponse
    {
        $roles = $this->roleService->getPaginatedRoles();
        $roles->through(fn (Role $role) => new RoleResource($role));

        return $this->successPaginatedResponse($roles, 'Daftar role berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/roles/{id}',
        summary: 'Get role detail with permissions',
        security: [['bearerAuth' => []]],
        tags: ['Roles'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Role detail retrieved successfully'),
            new OA\Response(response: 404, description: 'Role not found'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $role = $this->roleService->getRoleDetail($id);

        return $this->successResponse(new RoleResource($role), 'Detail role berhasil diambil.');
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
                    new OA\Property(property: 'name', type: 'string', example: 'security'),
                    new OA\Property(property: 'description', type: 'string', example: 'Orang yang bertanggung jawab menjaga keamanan', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Role created successfully'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 403, description: 'Forbidden access'),
        ]
    )]
    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = $this->roleService->createRole($request->validated());

        return $this->successResponse(new RoleResource($role), 'Role berhasil dibuat.', 201);
    }

    #[OA\Put(
        path: '/api/v1/roles/{id}',
        summary: 'Update an existing role',
        security: [['bearerAuth' => []]],
        tags: ['Roles'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'security_lead'),
                    new OA\Property(property: 'description', type: 'string', example: 'Pemimpin regu security', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Role updated successfully'),
            new OA\Response(response: 404, description: 'Role not found'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 403, description: 'Forbidden access'),
        ]
    )]
    public function update(UpdateRoleRequest $request, string $id): JsonResponse
    {
        $role = $this->roleService->updateRole($id, $request->validated());

        return $this->successResponse(new RoleResource($role), 'Role berhasil diperbarui.');
    }

    #[OA\Put(
        path: '/api/v1/roles/{id}/permissions',
        summary: 'Sync permissions of a role',
        security: [['bearerAuth' => []]],
        tags: ['Roles'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['permissions'],
                properties: [
                    new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(type: 'string', example: 'view-user'), description: 'Daftar nama permission; array kosong mencabut semua'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Permissions synced successfully'),
            new OA\Response(response: 403, description: 'Owner role cannot be modified'),
            new OA\Response(response: 404, description: 'Role not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function syncPermissions(SyncRolePermissionsRequest $request, string $id): JsonResponse
    {
        $role = $this->roleService->syncPermissions($id, $request->validated('permissions'));

        return $this->successResponse(new RoleResource($role), 'Permission role berhasil disinkronkan.');
    }

    #[OA\Delete(
        path: '/api/v1/roles/{id}',
        summary: 'Delete a role',
        security: [['bearerAuth' => []]],
        tags: ['Roles'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Role deleted successfully'),
            new OA\Response(response: 404, description: 'Role not found'),
            new OA\Response(response: 422, description: 'Role still in use'),
            new OA\Response(response: 403, description: 'Forbidden access'),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $this->roleService->deleteRole($id);

        return $this->successResponse(null, 'Role berhasil dihapus.');
    }
}
