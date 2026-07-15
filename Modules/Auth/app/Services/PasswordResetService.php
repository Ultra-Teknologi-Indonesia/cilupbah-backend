<?php

namespace Modules\Auth\Services;

use App\Mail\PasswordResetOtpMail;
use App\Models\PasswordResetOtp;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Auth\Exceptions\PasswordResetException;
use Modules\Auth\Repositories\UserRepository;

class PasswordResetService
{
    public const OTP_TTL_MINUTES = 10;

    public const RESET_TOKEN_TTL_MINUTES = 15;

    public const MAX_ATTEMPTS = 5;

    public function __construct(
        protected UserRepository $userRepository,
    ) {}

    public function sendOtp(string $email, ?Request $request = null): void
    {
        $email = strtolower(trim($email));
        $user = $this->userRepository->findByEmail($email);

        if (! $user) {
            Log::info('Password reset request untuk email tidak terdaftar', ['email' => $email]);

            return;
        }

        PasswordResetOtp::where('email', $email)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->update(['expires_at' => now()]);

        $otp = (string) random_int(100000, 999999);

        PasswordResetOtp::create([
            'email' => $email,
            'otp_hash' => Hash::make($otp),
            'expires_at' => now()->addMinutes(self::OTP_TTL_MINUTES),
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 490, '') : null,
        ]);

        Mail::to($email)->send(new PasswordResetOtpMail(
            otp: $otp,
            userName: (string) ($user->name ?? ''),
            ttlMinutes: self::OTP_TTL_MINUTES,
        ));
    }

    /**
     * @return array{reset_token:string, expires_at:Carbon}
     */
    public function verifyOtp(string $email, string $otp): array
    {
        $email = strtolower(trim($email));

        $row = PasswordResetOtp::where('email', $email)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (! $row) {
            throw new PasswordResetException(
                title: 'Kode OTP tidak berlaku',
                userMessage: 'Kode belum diminta atau sudah kedaluwarsa. Kembali ke halaman lupa kata sandi dan minta kode baru.',
                errors: ['otp' => ['Kode OTP tidak berlaku.']],
            );
        }

        if ($row->attempts >= self::MAX_ATTEMPTS) {
            $row->update(['expires_at' => now()]);
            throw new PasswordResetException(
                title: 'Terlalu banyak percobaan',
                userMessage: 'Anda salah memasukkan kode terlalu banyak. Demi keamanan, kode ini dinonaktifkan — silakan minta kode baru.',
                errors: ['otp' => ['Kode dinonaktifkan setelah 5 kali salah.']],
            );
        }

        if (! Hash::check($otp, $row->otp_hash)) {
            $row->increment('attempts');
            $sisa = max(0, self::MAX_ATTEMPTS - $row->attempts);
            throw new PasswordResetException(
                title: 'Kode OTP salah',
                userMessage: $sisa > 0
                    ? "Periksa kembali 6 digit kode yang kami kirim ke email Anda. Sisa {$sisa} percobaan sebelum kode dinonaktifkan."
                    : 'Periksa kembali 6 digit kode yang kami kirim ke email Anda.',
                errors: ['otp' => ['Kode OTP salah.']],
            );
        }

        $plainToken = Str::random(64);
        $expiresAt = now()->addMinutes(self::RESET_TOKEN_TTL_MINUTES);

        $row->update([
            'verified_at' => now(),
            'reset_token_hash' => Hash::make($plainToken),
            'reset_token_expires_at' => $expiresAt,
        ]);

        return [
            'reset_token' => $plainToken,
            'expires_at' => $expiresAt,
        ];
    }

    public function resetPassword(string $email, string $token, string $newPassword): void
    {
        $email = strtolower(trim($email));

        $row = PasswordResetOtp::where('email', $email)
            ->whereNotNull('verified_at')
            ->whereNull('used_at')
            ->whereNotNull('reset_token_hash')
            ->where('reset_token_expires_at', '>', now())
            ->latest('id')
            ->first();

        if (! $row || ! Hash::check($token, (string) $row->reset_token_hash)) {
            throw new PasswordResetException(
                title: 'Sesi reset kedaluwarsa',
                userMessage: 'Sesi reset kata sandi Anda sudah tidak berlaku (lewat 15 menit atau tautan tidak cocok). Silakan mulai ulang dari halaman lupa kata sandi.',
                errors: ['reset_token' => ['Sesi reset tidak valid.']],
            );
        }

        $user = $this->userRepository->findByEmail($email);
        if (! $user) {
            throw new PasswordResetException(
                title: 'Akun tidak ditemukan',
                userMessage: 'Kami tidak dapat menemukan akun untuk email ini. Silakan mulai ulang dari halaman lupa kata sandi.',
                errors: ['email' => ['Akun tidak ditemukan.']],
            );
        }

        $user->password = $newPassword;
        $user->save();

        $this->userRepository->deleteTokens($user);

        $row->update(['used_at' => now()]);
    }
}
