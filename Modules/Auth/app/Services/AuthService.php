<?php

namespace Modules\Auth\Services;

use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Modules\Auth\Repositories\UserRepository;

class AuthService
{
    public function __construct(
        protected UserRepository $userRepository
    ) {}

    public function login(array $credentials, ?Request $request = null): ?array
    {
        $user = $this->userRepository->findByEmail($credentials['email']);

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return null;
        }

        $this->userRepository->update($user, ['last_login_at' => now()]);

        if ($request) {
            $clientIp = $request->header('X-Client-IP')
                ?? $request->header('CF-Connecting-IP')
                ?? $request->header('X-Forwarded-For')
                ?? $request->ip()
                ?? '';
            if (str_contains($clientIp, ',')) {
                $clientIp = trim(explode(',', $clientIp)[0]);
            }

            LoginHistory::recordLogin(
                $user->id,
                $clientIp,
                $request->userAgent() ?? ''
            );
        }

        $user->load('roles', 'permissions');
        $token = $user->createToken('auth_token')->plainTextToken;

        return [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
        ];
    }

    public function logout(User $user): void
    {
        $this->userRepository->deleteCurrentToken($user);
    }

    public function getProfile(User $user): User
    {
        return $user->load('roles', 'permissions');
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword): bool
    {
        if (! Hash::check($currentPassword, $user->password)) {
            return false;
        }

        $this->userRepository->update($user, ['password' => Hash::make($newPassword)]);

        return true;
    }
}
