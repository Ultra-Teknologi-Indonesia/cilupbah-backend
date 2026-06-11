<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Auth\Services\AuthService;
use Modules\Auth\Services\UserService;
use Modules\Auth\Http\Resources\ProfileResource;
use App\Traits\ApiResponse;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Auth', description: 'Authentication API Endpoints')]
class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AuthService $authService,
        protected UserService $userService
    ) {}

    #[OA\Post(
        path: '/api/v1/auth/login',
        summary: 'Login user and get access token',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'test@example.com'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: 'password'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful login',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'success'),
                        new OA\Property(property: 'message', type: 'string', example: 'Berhasil masuk. Selamat datang kembali!'),
                        new OA\Property(property: 'data', type: 'object', properties: [
                            new OA\Property(property: 'access_token', type: 'string', example: '1|xyz...'),
                            new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
                            new OA\Property(property: 'user', type: 'object', properties: [
                                new OA\Property(property: 'id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
                                new OA\Property(property: 'name', type: 'string', example: 'Test User'),
                                new OA\Property(property: 'email', type: 'string', example: 'test@example.com'),
                                new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string', example: 'admin')),
                                new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(type: 'string', example: 'create-user'))
                            ])
                        ])
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Email atau kata sandi yang Anda masukkan salah.')
        ]
    )]
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $data = $this->authService->login($credentials);

        if (! $data) {
            return $this->errorResponse('Email atau kata sandi yang Anda masukkan salah.', 401);
        }

        $responseData = [
            'access_token' => $data['access_token'],
            'token_type'   => $data['token_type'],
            'user'         => new ProfileResource($data['user']),
        ];

        return $this->successResponse($responseData, 'Berhasil masuk. Selamat datang kembali!');
    }

    #[OA\Get(
        path: '/api/v1/profile',
        summary: 'Get current authenticated user profile',
        security: [['bearerAuth' => []]],
        tags: ['Auth'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'success'),
                        new OA\Property(property: 'message', type: 'string', example: 'Profil berhasil dimuat.'),
                        new OA\Property(property: 'data', type: 'object', properties: [
                            new OA\Property(property: 'id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
                            new OA\Property(property: 'name', type: 'string', example: 'Test User'),
                            new OA\Property(property: 'email', type: 'string', example: 'test@example.com'),
                            new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string', example: 'admin')),
                            new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(type: 'string', example: 'create-user'))
                        ])
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated')
        ]
    )]
    public function profile(Request $request): JsonResponse
    {
        $profile = $this->authService->getProfile($request->user());

        return $this->successResponse(new ProfileResource($profile), 'Profil berhasil dimuat.');
    }

    #[OA\Put(
        path: '/api/v1/profile/avatar',
        summary: 'Set or remove current user avatar (referensi media terpusat)',
        security: [['bearerAuth' => []]],
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['media_uuid'],
                properties: [
                    new OA\Property(property: 'media_uuid', type: 'string', nullable: true, description: 'UUID media dari POST /media/upload; null untuk melepas avatar', example: '9f8c1e2a-...')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Avatar diperbarui'),
            new OA\Response(response: 422, description: 'Validasi gagal'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function updateAvatar(\Modules\Auth\Http\Requests\UpdateAvatarRequest $request): JsonResponse
    {
        $user = $this->userService->setAvatar($request->user(), $request->input('media_uuid'));

        return $this->successResponse(new ProfileResource($user), 'Avatar berhasil diperbarui.');
    }

    #[OA\Post(
        path: '/api/v1/auth/logout',
        summary: 'Logout user and revoke access token',
        security: [['bearerAuth' => []]],
        tags: ['Auth'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful logout',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'success'),
                        new OA\Property(property: 'message', type: 'string', example: 'Anda telah berhasil keluar.'),
                        new OA\Property(property: 'data', type: 'object', nullable: true)
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated')
        ]
    )]
    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return $this->successResponse(null, 'Anda telah berhasil keluar.');
    }
}
