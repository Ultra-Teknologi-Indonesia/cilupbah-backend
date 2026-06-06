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
}
