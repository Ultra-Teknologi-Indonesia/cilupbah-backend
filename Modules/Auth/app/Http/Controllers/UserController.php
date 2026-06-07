<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Auth\Services\UserService;
use Modules\Auth\Http\Requests\StoreUserRequest;
use Modules\Auth\Http\Resources\ProfileResource;
use App\Traits\ApiResponse;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Users', description: 'User Management Endpoints')]
class UserController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected UserService $userService
    ) {}

    #[OA\Get(
        path: '/api/v1/users',
        summary: 'Get all users (Paginated)',
        security: [['bearerAuth' => []]],
        tags: ['Users'],
        parameters: [
            new OA\Parameter(name: 'filter[role]', in: 'query', description: 'Filter by exact role name', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[warehouse_id]', in: 'query', description: 'Filter by exact warehouse ID', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', description: 'Search by name or email', required: false, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of users retrieved successfully'),
            new OA\Response(response: 401, description: 'Unauthenticated')
        ]
    )]
    public function index(): JsonResponse
    {
        $users = $this->userService->getPaginatedUsers();

        return $this->successPaginatedResponse(
            ProfileResource::collection($users)
        );
    }

    #[OA\Get(
        path: '/api/v1/users/export',
        summary: 'Export all users to Excel',
        security: [['bearerAuth' => []]],
        tags: ['Users'],
        parameters: [
            new OA\Parameter(name: 'filter[role]', in: 'query', description: 'Filter by exact role name', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[warehouse_id]', in: 'query', description: 'Filter by exact warehouse ID', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', description: 'Search by name or email', required: false, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Excel file generated'),
            new OA\Response(response: 401, description: 'Unauthenticated')
        ]
    )]
    public function export()
    {
        $query = $this->userService->getExportUsersQuery();
        
        return \Maatwebsite\Excel\Facades\Excel::download(
            new \Modules\Auth\Exports\UsersExport($query),
            'users_export_' . now()->format('Ymd_His') . '.xlsx'
        );
    }

    #[OA\Post(
        path: '/api/v1/users',
        summary: 'Create a new user',
        security: [['bearerAuth' => []]],
        tags: ['Users'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'email', 'password', 'password_confirmation', 'roles'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'John Doe'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'john@example.com'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: 'StrongP@ssw0rd!'),
                    new OA\Property(property: 'password_confirmation', type: 'string', format: 'password', example: 'StrongP@ssw0rd!'),
                    new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string', example: 'picker')),
                    new OA\Property(property: 'nik', type: 'string', nullable: true, example: '3201012345678901'),
                    new OA\Property(property: 'warehouse_id', type: 'string', nullable: true, example: '019ea2afad1d733eafb905816d10590e'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'User created successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'success'),
                        new OA\Property(property: 'message', type: 'string', example: 'Pengguna berhasil dibuat.'),
                        new OA\Property(property: 'data', type: 'object', properties: [
                            new OA\Property(property: 'id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
                            new OA\Property(property: 'name', type: 'string', example: 'John Doe'),
                            new OA\Property(property: 'email', type: 'string', example: 'john@example.com'),
                            new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string', example: 'picker')),
                            new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(type: 'string'))
                        ])
                    ]
                )
            ),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 403, description: 'Forbidden access')
        ]
    )]
    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->userService->createUser($request->validated());

        return $this->successResponse(new ProfileResource($user), 'Pengguna berhasil dibuat.', 201);
    }

    #[OA\Put(
        path: '/api/v1/users/{id}',
        summary: 'Update an existing user',
        security: [['bearerAuth' => []]],
        tags: ['Users'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'User ID',
                schema: new OA\Schema(type: 'string', example: '019ea2afad1d733eafb905816d10590e')
            )
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'email', 'roles'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'John Doe Updated'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'john.updated@example.com'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', nullable: true, example: 'NewStrongP@ssw0rd!'),
                    new OA\Property(property: 'password_confirmation', type: 'string', format: 'password', nullable: true, example: 'NewStrongP@ssw0rd!'),
                    new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string', example: 'checker')),
                    new OA\Property(property: 'nik', type: 'string', nullable: true, example: '3201012345678901'),
                    new OA\Property(property: 'warehouse_id', type: 'string', nullable: true, example: '019ea2afad1d733eafb905816d10590e'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'User updated successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'success'),
                        new OA\Property(property: 'message', type: 'string', example: 'Pengguna berhasil diperbarui.'),
                        new OA\Property(property: 'data', type: 'object', properties: [
                            new OA\Property(property: 'id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
                            new OA\Property(property: 'name', type: 'string', example: 'John Doe Updated'),
                            new OA\Property(property: 'email', type: 'string', example: 'john.updated@example.com'),
                            new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string', example: 'checker')),
                            new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(type: 'string'))
                        ])
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'User not found'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 403, description: 'Forbidden access')
        ]
    )]
    public function update(\Modules\Auth\Http\Requests\UpdateUserRequest $request, string $id): JsonResponse
    {
        $user = $this->userService->updateUser($id, $request->validated());

        return $this->successResponse(new ProfileResource($user), 'Pengguna berhasil diperbarui.');
    }

    #[OA\Get(
        path: '/api/v1/users/{id}/histories',
        summary: 'Get user history',
        security: [['bearerAuth' => []]],
        tags: ['Users'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'User ID',
                schema: new OA\Schema(type: 'string', example: '019ea2afad1d733eafb905816d10590e')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'User histories retrieved successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'success'),
                        new OA\Property(property: 'message', type: 'string', example: 'Riwayat pengguna berhasil dimuat.'),
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
                                    new OA\Property(property: 'actor', type: 'object', nullable: true, properties: [
                                        new OA\Property(property: 'id', type: 'string'),
                                        new OA\Property(property: 'name', type: 'string'),
                                        new OA\Property(property: 'email', type: 'string')
                                    ]),
                                    new OA\Property(property: 'target_user_id', type: 'string'),
                                    new OA\Property(property: 'action', type: 'string', example: 'updated'),
                                    new OA\Property(property: 'message', type: 'string', example: 'Admin membuat akun ini.'),
                                    new OA\Property(property: 'created_at', type: 'string', format: 'date-time')
                                ]
                            )
                        )
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'User not found')
        ]
    )]
    public function histories(string $id): JsonResponse
    {
        $histories = $this->userService->getUserHistories($id);

        return $this->successPaginatedResponse(
            \App\Http\Resources\UserHistoryResource::collection($histories)
        );
    }

    #[OA\Post(
        path: '/api/v1/users/{id}/force-logout',
        summary: 'Force logout a single user',
        security: [['bearerAuth' => []]],
        tags: ['Users'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'User ID',
                schema: new OA\Schema(type: 'string', example: '019ea2afad1d733eafb905816d10590e')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'User force logged out successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'success'),
                        new OA\Property(property: 'message', type: 'string', example: 'Sesi pengguna berhasil diputus.')
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'User not found'),
            new OA\Response(response: 403, description: 'Forbidden access')
        ]
    )]
    public function forceLogout(string $id): JsonResponse
    {
        $this->userService->forceLogout($id);

        return $this->successResponse(null, 'Sesi pengguna berhasil diputus.');
    }

    #[OA\Post(
        path: '/api/v1/users/bulk-force-logout',
        summary: 'Bulk force logout multiple users',
        security: [['bearerAuth' => []]],
        tags: ['Users'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['user_ids'],
                properties: [
                    new OA\Property(property: 'user_ids', type: 'array', items: new OA\Items(type: 'string', example: '019ea2afad1d733eafb905816d10590e')),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Users force logged out successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'success'),
                        new OA\Property(property: 'message', type: 'string', example: 'Sesi pengguna yang dipilih berhasil diputus.')
                    ]
                )
            ),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 403, description: 'Forbidden access')
        ]
    )]
    public function bulkForceLogout(\Illuminate\Http\Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['required', 'string', 'exists:users,id'],
        ]);

        $this->userService->bulkForceLogout($validated['user_ids']);

        return $this->successResponse(null, 'Sesi pengguna yang dipilih berhasil diputus.');
    }
}
