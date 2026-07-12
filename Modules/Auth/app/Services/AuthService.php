<?php

namespace Modules\Auth\Services;

use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
            if ($request) {
                [$clientType, $clientIp, $userAgent] = $this->resolveClientMeta($request);
                LoginHistory::recordFailed(
                    (string) ($credentials['email'] ?? ''),
                    $clientIp,
                    $userAgent,
                    $clientType
                );
            }

            return null;
        }

        $this->userRepository->update($user, ['last_login_at' => now()]);

        $clientType = LoginHistory::CLIENT_WEB;
        $clientIp = '';
        $userAgent = '';
        if ($request) {
            [$clientType, $clientIp, $userAgent] = $this->resolveClientMeta($request);
        }

        $user->load('roles', 'permissions');

        $tokenName = $this->buildTokenName($clientType, $userAgent);
        $newToken = $user->createToken($tokenName);
        $accessToken = $newToken->plainTextToken;

        if ($request) {
            LoginHistory::recordLogin(
                $user->id,
                $clientIp,
                $userAgent,
                $clientType,
                (int) $newToken->accessToken->getKey()
            );
        }

        return [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'user' => $user,
        ];
    }

    /**
     * @return array{0:string,1:string,2:string} [clientType, ip, userAgent]
     */
    private function resolveClientMeta(Request $request): array
    {
        $clientTypeRaw = strtolower((string) $request->header('X-Client-Type', ''));
        $clientType = $clientTypeRaw === LoginHistory::CLIENT_MOBILE
            ? LoginHistory::CLIENT_MOBILE
            : LoginHistory::CLIENT_WEB;

        $clientIp = $request->header('X-Client-IP')
            ?? $request->header('CF-Connecting-IP')
            ?? $request->header('X-Forwarded-For')
            ?? $request->ip()
            ?? '';
        if (str_contains($clientIp, ',')) {
            $clientIp = trim(explode(',', $clientIp)[0]);
        }

        return [$clientType, $clientIp, $request->userAgent() ?? ''];
    }

    private function buildTokenName(string $clientType, string $userAgent): string
    {
        $label = trim($userAgent);
        if ($label === '') {
            $label = $clientType === LoginHistory::CLIENT_MOBILE ? 'Cilupbah App' : 'Web Browser';
        }
        $label = mb_substr($label, 0, 120);

        return $clientType.':'.$label;
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

    public function updateProfile(User $user, array $data): User
    {
        $updateData = [];
        foreach (['name', 'nik', 'phone'] as $field) {
            if (array_key_exists($field, $data)) {
                $updateData[$field] = $data[$field] === '' ? null : $data[$field];
            }
        }

        if (! empty($updateData)) {
            $this->userRepository->update($user, $updateData);
        }

        return $user->refresh()->load('roles', 'permissions');
    }

    public function listSessions(User $user, ?string $currentTokenId): Collection
    {
        return DB::table('personal_access_tokens')
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->id)
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'last_used_at', 'created_at', 'expires_at'])
            ->map(function ($row) use ($currentTokenId) {
                return [
                    'id' => (string) $row->id,
                    'name' => $row->name,
                    'last_used_at' => $row->last_used_at,
                    'created_at' => $row->created_at,
                    'expires_at' => $row->expires_at,
                    'is_current' => $currentTokenId !== null && (string) $row->id === $currentTokenId,
                ];
            });
    }

    public function revokeSession(User $user, string $tokenId, ?string $currentTokenId): void
    {
        if ($currentTokenId !== null && $tokenId === $currentTokenId) {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(
                422,
                'Tidak dapat mencabut sesi saat ini di sini — gunakan tombol Keluar.'
            );
        }

        DB::table('personal_access_tokens')
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->id)
            ->where('id', $tokenId)
            ->delete();
    }

    public function revokeOtherSessions(User $user, ?string $currentTokenId): int
    {
        $query = DB::table('personal_access_tokens')
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->id);

        if ($currentTokenId !== null) {
            $query->where('id', '!=', $currentTokenId);
        }

        return $query->delete();
    }
}
