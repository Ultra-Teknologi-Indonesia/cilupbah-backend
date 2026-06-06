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
                    new OA\Property(property: 'warehouse_id', type: 'string', nullable: true, example: '018f6b...'),
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
                            new OA\Property(property: 'id', type: 'string', example: '018f6b...'),
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
                schema: new OA\Schema(type: 'string', example: '018f6b...')
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
                    new OA\Property(property: 'warehouse_id', type: 'string', nullable: true, example: '018f6b...'),
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
                            new OA\Property(property: 'id', type: 'string', example: '018f6b...'),
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
                schema: new OA\Schema(type: 'string', example: '018f6b...')
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
                                    new OA\Property(property: 'id', type: 'string', example: '018f6b...'),
                                    new OA\Property(property: 'actor', type: 'object', nullable: true, properties: [
                                        new OA\Property(property: 'id', type: 'string'),
                                        new OA\Property(property: 'name', type: 'string'),
                                        new OA\Property(property: 'email', type: 'string')
                                    ]),
                                    new OA\Property(property: 'target_user_id', type: 'string'),
                                    new OA\Property(property: 'action', type: 'string', example: 'updated'),
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
        $user = \App\Models\User::findOrFail($id);
        
        $histories = \App\Models\UserHistory::with('actor')
            ->where('target_user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->successResponse(
            \App\Http\Resources\UserHistoryResource::collection($histories), 
            'Riwayat pengguna berhasil dimuat.'
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
                schema: new OA\Schema(type: 'string', example: '018f6b...')
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
        $user = \App\Models\User::findOrFail($id);
        
        $user->tokens()->delete();

        \App\Models\UserHistory::create([
            'actor_id' => \Illuminate\Support\Facades\Auth::id(),
            'target_user_id' => $user->id,
            'action' => 'force_logged_out',
        ]);

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
                    new OA\Property(property: 'user_ids', type: 'array', items: new OA\Items(type: 'string', example: '018f6b...')),
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

        $actorId = \Illuminate\Support\Facades\Auth::id();

        foreach ($validated['user_ids'] as $userId) {
            $user = \App\Models\User::find($userId);
            if ($user) {
                $user->tokens()->delete();

                \App\Models\UserHistory::create([
                    'actor_id' => $actorId,
                    'target_user_id' => $user->id,
                    'action' => 'force_logged_out',
                ]);
            }
        }

        return $this->successResponse(null, 'Sesi pengguna yang dipilih berhasil diputus.');
    }
}
