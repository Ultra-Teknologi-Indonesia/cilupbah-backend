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
    /** Umur access token — token untuk hit endpoint API biasa. */
    public const ACCESS_TOKEN_TTL_MINUTES = 60;

    /** Umur refresh token — untuk minta access token baru tanpa login ulang. */
    public const REFRESH_TOKEN_TTL_DAYS = 30;

    /**
     * Ability marker untuk refresh token. Access token pakai default ['*'];
     * refresh token dibatasi ke ['refresh'] supaya kalau bocor tidak bisa
     * dipakai untuk memanggil endpoint API lain.
     */
    public const ABILITY_REFRESH = 'refresh';

    public function __construct(
        protected UserRepository $userRepository
    ) {}

    public function login(array $credentials, ?Request $request = null): ?array
    {
        $user = $this->userRepository->findByEmail($credentials['email']);

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            if ($request) {
                $meta = $this->resolveClientMeta($request);
                LoginHistory::recordFailed(
                    (string) ($credentials['email'] ?? ''),
                    $meta['ip'],
                    $meta['userAgent'],
                    $meta['clientType'],
                    $meta['deviceModel'],
                    $meta['deviceManufacturer']
                );
            }

            return null;
        }

        $this->userRepository->update($user, ['last_login_at' => now()]);

        $meta = $request
            ? $this->resolveClientMeta($request)
            : ['clientType' => LoginHistory::CLIENT_WEB, 'ip' => '', 'userAgent' => '', 'deviceModel' => null, 'deviceManufacturer' => null];

        $user->load('roles', 'permissions');

        $tokenName = $this->buildTokenName($meta['clientType'], $meta['userAgent'], $meta['deviceModel']);
        $pair = $this->issueTokenPair($user, $tokenName);

        if ($request) {
            LoginHistory::recordLogin(
                $user->id,
                $meta['ip'],
                $meta['userAgent'],
                $meta['clientType'],
                (int) $pair['access_token_id'],
                $meta['deviceModel'],
                $meta['deviceManufacturer']
            );
        }

        return [
            'access_token'  => $pair['access_token'],
            'refresh_token' => $pair['refresh_token'],
            'expires_in'    => self::ACCESS_TOKEN_TTL_MINUTES * 60,
            'refresh_expires_in' => self::REFRESH_TOKEN_TTL_DAYS * 86400,
            'token_type'    => 'Bearer',
            'user'          => $user,
        ];
    }

    /**
     * Tukar refresh token ke access token baru. Refresh token lama
     * di-revoke (rotasi) supaya replay attack dari token yang bocor
     * langsung mati saat token asli sudah dipakai refresh.
     *
     * $current adalah PersonalAccessToken yang dipakai untuk hit endpoint
     * refresh (harus punya ability 'refresh').
     */
    public function refresh(User $user, \Laravel\Sanctum\PersonalAccessToken $current): array
    {
        $user->load('roles', 'permissions');
        $tokenName = $this->deriveNameForRotation($current->name);

        // Revoke refresh token yg dipakai (rotation) + revoke access token
        // lama untuk device yg sama supaya tidak ada 2 access token aktif
        // sekaligus.
        DB::table('personal_access_tokens')
            ->where('id', $current->id)
            ->orWhere(function ($q) use ($user, $tokenName) {
                $q->where('tokenable_id', $user->id)
                  ->where('tokenable_type', $user::class)
                  ->where('name', $tokenName);
            })
            ->delete();

        $pair = $this->issueTokenPair($user, $tokenName);

        return [
            'access_token'  => $pair['access_token'],
            'refresh_token' => $pair['refresh_token'],
            'expires_in'    => self::ACCESS_TOKEN_TTL_MINUTES * 60,
            'refresh_expires_in' => self::REFRESH_TOKEN_TTL_DAYS * 86400,
            'token_type'    => 'Bearer',
            'user'          => $user,
        ];
    }

    /**
     * Bikin sepasang token untuk 1 device. Access token pendek + refresh
     * token panjang. Nama refresh token = access name + ":refresh" supaya
     * bisa dikaitkan balik saat rotasi.
     *
     * @return array{access_token: string, refresh_token: string, access_token_id: int|string}
     */
    protected function issueTokenPair(User $user, string $tokenName): array
    {
        $access = $user->createToken(
            $tokenName,
            ['*'],
            now()->addMinutes(self::ACCESS_TOKEN_TTL_MINUTES),
        );
        $refresh = $user->createToken(
            $tokenName . ':refresh',
            [self::ABILITY_REFRESH],
            now()->addDays(self::REFRESH_TOKEN_TTL_DAYS),
        );

        return [
            'access_token'   => $access->plainTextToken,
            'refresh_token'  => $refresh->plainTextToken,
            'access_token_id' => $access->accessToken->getKey(),
        ];
    }

    /**
     * Kalau nama token yg dipakai adalah refresh (ada suffix ":refresh"),
     * kembalikan nama access-nya. Kalau tidak ada suffix, pakai apa adanya.
     */
    protected function deriveNameForRotation(string $tokenName): string
    {
        if (str_ends_with($tokenName, ':refresh')) {
            return substr($tokenName, 0, -strlen(':refresh'));
        }
        return $tokenName;
    }

    /** Suffix penanda refresh token pada kolom name. */
    public const REFRESH_NAME_SUFFIX = ':refresh';

    /**
     * Akses & refresh token selalu terbit berpasangan dengan nama
     * "N" dan "N:refresh". Semua pencabutan sesi harus mengenai keduanya —
     * kalau hanya access token yang dicabut, refresh token yang tertinggal
     * akan mencetak access token baru dan sesi itu hidup lagi.
     *
     * @return array<int,string> id baris yang menjadi satu pasangan
     */
    protected function pairTokenIds(User $user, string $tokenId): array
    {
        $row = DB::table('personal_access_tokens')
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->id)
            ->where('id', $tokenId)
            ->first(['id', 'name']);

        if (! $row) {
            return [$tokenId];
        }

        $base = $this->deriveNameForRotation($row->name);

        return DB::table('personal_access_tokens')
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->id)
            ->whereIn('name', [$base, $base.self::REFRESH_NAME_SUFFIX])
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

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

        return [
            'clientType' => $clientType,
            'ip' => $clientIp,
            'userAgent' => $request->userAgent() ?? '',
            'deviceModel' => $request->header('X-Device-Model'),
            'deviceManufacturer' => $request->header('X-Device-Manufacturer'),
        ];
    }

    private function buildTokenName(string $clientType, string $userAgent, ?string $deviceModel = null): string
    {
        if ($clientType === LoginHistory::CLIENT_MOBILE && $deviceModel !== null && trim($deviceModel) !== '') {
            $label = trim($deviceModel);
        } else {
            $label = trim($userAgent);
            if ($label === '') {
                $label = $clientType === LoginHistory::CLIENT_MOBILE ? 'Cilupbah App' : 'Web Browser';
            }
        }
        $label = mb_substr($label, 0, 120);

        return $clientType.':'.$label;
    }

    /**
     * Logout harus mencabut access DAN refresh token. Kalau hanya access
     * token yang dihapus, refresh token tetap hidup sampai 30 hari dan
     * masih bisa dipakai mencetak access token baru — sesi tidak
     * benar-benar berakhir.
     */
    public function logout(User $user): void
    {
        $current = $user->currentAccessToken();

        if (! $current) {
            return;
        }

        DB::table('personal_access_tokens')
            ->whereIn('id', $this->pairTokenIds($user, (string) $current->getKey()))
            ->delete();
    }

    /** Verifikasi password user untuk membuka idle lock. */
    public function verifyPassword(User $user, string $password): bool
    {
        return Hash::check($password, $user->password);
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

    /**
     * Satu sesi = satu pasang token, jadi baris ":refresh" tidak ditampilkan
     * sebagai sesi tersendiri. Tapi `expires_at` diambil dari baris refresh,
     * karena itulah yang menentukan kapan sesi benar-benar berakhir —
     * access token cuma berumur 60 menit dan terus diperbarui.
     */
    public function listSessions(User $user, ?string $currentTokenId): Collection
    {
        $rows = DB::table('personal_access_tokens')
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->id)
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'last_used_at', 'created_at', 'expires_at']);

        $refreshByBase = $rows
            ->filter(fn ($row) => str_ends_with($row->name, self::REFRESH_NAME_SUFFIX))
            ->keyBy(fn ($row) => $this->deriveNameForRotation($row->name));

        $currentBase = null;
        if ($currentTokenId !== null) {
            $currentRow = $rows->first(fn ($row) => (string) $row->id === $currentTokenId);
            if ($currentRow) {
                $currentBase = $this->deriveNameForRotation($currentRow->name);
            }
        }

        return $rows
            ->reject(fn ($row) => str_ends_with($row->name, self::REFRESH_NAME_SUFFIX))
            ->values()
            ->map(function ($row) use ($refreshByBase, $currentBase) {
                $refresh = $refreshByBase->get($row->name);

                return [
                    'id' => (string) $row->id,
                    'name' => $row->name,
                    'last_used_at' => $row->last_used_at,
                    'created_at' => $row->created_at,
                    'expires_at' => $refresh->expires_at ?? $row->expires_at,
                    'is_current' => $currentBase !== null && $row->name === $currentBase,
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

        // Cabut sepasang — kalau refresh token pasangannya tertinggal,
        // sesi yang "sudah dicabut" akan hidup lagi lewat /auth/refresh.
        DB::table('personal_access_tokens')
            ->whereIn('id', $this->pairTokenIds($user, $tokenId))
            ->delete();
    }

    /**
     * Kecualikan SEPASANG token milik sesi berjalan, bukan hanya access
     * token-nya. Kalau refresh token sendiri ikut terhapus, user yang
     * menekan "cabut sesi lain" akan ter-logout paksa begitu access
     * token-nya kedaluwarsa.
     */
    public function revokeOtherSessions(User $user, ?string $currentTokenId): int
    {
        $query = DB::table('personal_access_tokens')
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->id);

        if ($currentTokenId !== null) {
            $query->whereNotIn('id', $this->pairTokenIds($user, $currentTokenId));
        }

        $deleted = $query->delete();

        // Hitung per sesi (pasangan), bukan per baris token, supaya angka
        // yang muncul di UI cocok dengan jumlah sesi yang ditampilkan.
        return (int) ceil($deleted / 2);
    }
}
