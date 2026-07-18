<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\LoginHistoryResource;
use App\Http\Resources\UserHistoryResource;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Auth\Http\Requests\ChangePasswordRequest;
use Modules\Auth\Http\Requests\LoginRequest;
use Modules\Auth\Http\Requests\UpdateAvatarRequest;
use Modules\Auth\Http\Requests\UpdateProfileRequest;
use Modules\Auth\Http\Resources\ProfileResource;
use Modules\Auth\Services\AuthService;
use Modules\Auth\Services\UserService;
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
    public function login(LoginRequest $request): JsonResponse
    {
        $data = $this->authService->login($request->validated(), $request);

        if (! $data) {
            return $this->errorResponse('Email atau kata sandi yang Anda masukkan salah.', 401);
        }

        $responseData = [
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'expires_in'    => $data['expires_in'],
            'refresh_expires_in' => $data['refresh_expires_in'],
            'token_type'    => $data['token_type'],
            'user'          => new ProfileResource($this->userService->attachProfileContext($data['user'])),
        ];

        return $this->successResponse($responseData, 'Berhasil masuk. Selamat datang kembali!');
    }

    #[OA\Post(
        path: '/api/v1/auth/refresh',
        summary: 'Tukar refresh token dengan pasangan access + refresh baru',
        description: 'Kirim Authorization: Bearer <refresh_token>. Access token lama untuk device yang sama otomatis dicabut. Refresh token yg dipakai juga dirotasi (dicabut & diganti baru). Kalau refresh token tidak valid / expired / bukan token refresh, balas 401 — mobile harus paksa login ulang.',
        security: [['bearerAuth' => []]],
        tags: ['Auth'],
        responses: [
            new OA\Response(response: 200, description: 'Token baru diterbitkan'),
            new OA\Response(response: 401, description: 'Refresh token invalid / bukan refresh token'),
        ]
    )]
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        // Endpoint ini hanya boleh dipanggil pakai refresh token —
        // access token biasa dgn ability '*' juga akan lolos `->can()`
        // (wildcard), jadi cek abilities secara ketat: harus HANYA
        // berisi 'refresh' dan tidak boleh punya '*'.
        $abilities = $token?->abilities ?? [];
        $isRefreshToken = ! in_array('*', $abilities, true)
            && in_array(AuthService::ABILITY_REFRESH, $abilities, true);

        if (! $token || ! $isRefreshToken) {
            return $this->errorResponse('Refresh token tidak valid.', 401);
        }

        $data = $this->authService->refresh($user, $token);

        return $this->successResponse([
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'expires_in'    => $data['expires_in'],
            'refresh_expires_in' => $data['refresh_expires_in'],
            'token_type'    => $data['token_type'],
            'user'          => new ProfileResource($this->userService->attachProfileContext($data['user'])),
        ], 'Token diperbarui.');
    }

    #[OA\Post(
        path: '/api/v1/auth/unlock',
        summary: 'Verifikasi ulang password untuk membuka idle lock',
        description: 'Dipakai saat layar terkunci karena tidak ada aktivitas. Hanya memverifikasi password user yang sedang login — tidak menerbitkan token baru dan tidak mencatat login history baru, supaya daftar sesi & riwayat login tidak penuh oleh aksi unlock.',
        security: [['bearerAuth' => []]],
        tags: ['Auth'],
        responses: [
            new OA\Response(response: 200, description: 'Password cocok, layar boleh dibuka'),
            new OA\Response(response: 422, description: 'Password tidak sesuai'),
        ]
    )]
    public function unlock(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
        ]);

        if (! $this->authService->verifyPassword($request->user(), $validated['password'])) {
            return $this->errorResponse('Kata sandi tidak sesuai.', 422);
        }

        return $this->successResponse(null, 'Selamat datang kembali.');
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
        $profile = $this->userService->attachProfileContext(
            $this->authService->getProfile($request->user())
        );

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
    public function updateAvatar(UpdateAvatarRequest $request): JsonResponse
    {
        $user = $this->userService->setAvatar($request->user(), $request->input('media_uuid'));

        return $this->successResponse(
            new ProfileResource($this->userService->attachProfileContext($user)),
            'Avatar berhasil diperbarui.'
        );
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $changed = $this->authService->changePassword(
            $request->user(),
            $request->input('current_password'),
            $request->input('new_password')
        );

        if (! $changed) {
            return $this->errorResponse('Kata sandi saat ini tidak sesuai.', 422);
        }

        return $this->successResponse(null, 'Kata sandi berhasil diubah.');
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $user = $this->authService->updateProfile($request->user(), $request->validated());

        return $this->successResponse(
            new ProfileResource($this->userService->attachProfileContext($user)),
            'Profil berhasil diperbarui.'
        );
    }

    public function myHistories(Request $request): JsonResponse
    {
        $histories = $this->userService->getUserHistories($request->user()->id);

        return $this->successPaginatedResponse(
            UserHistoryResource::collection($histories)
        );
    }

    public function myLoginHistories(Request $request): JsonResponse
    {
        $histories = $this->userService->getLoginHistories($request->user()->id);

        return $this->successPaginatedResponse(
            LoginHistoryResource::collection($histories)
        );
    }

    public function sessions(Request $request): JsonResponse
    {
        $currentTokenId = $this->currentTokenId($request);
        $sessions = $this->authService->listSessions($request->user(), $currentTokenId);

        return $this->successResponse($sessions, 'Sesi aktif berhasil dimuat.');
    }

    public function revokeSession(Request $request, string $id): JsonResponse
    {
        $currentTokenId = $this->currentTokenId($request);
        $this->authService->revokeSession($request->user(), $id, $currentTokenId);

        return $this->successResponse(null, 'Sesi berhasil dicabut.');
    }

    public function revokeOtherSessions(Request $request): JsonResponse
    {
        $currentTokenId = $this->currentTokenId($request);
        $count = $this->authService->revokeOtherSessions($request->user(), $currentTokenId);

        return $this->successResponse(
            ['revoked' => $count],
            $count > 0
                ? "Berhasil mencabut {$count} sesi lain."
                : 'Tidak ada sesi lain untuk dicabut.'
        );
    }

    protected function currentTokenId(Request $request): ?string
    {
        $token = $request->user()?->currentAccessToken();

        return $token?->id ? (string) $token->id : null;
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
