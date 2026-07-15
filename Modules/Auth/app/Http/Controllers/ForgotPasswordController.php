<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Auth\Http\Requests\ForgotPasswordRequest;
use Modules\Auth\Http\Requests\ResetPasswordRequest;
use Modules\Auth\Http\Requests\VerifyResetOtpRequest;
use Modules\Auth\Services\PasswordResetService;

class ForgotPasswordController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected PasswordResetService $service,
    ) {}

    public function send(ForgotPasswordRequest $request): JsonResponse
    {
        $this->service->sendOtp($request->input('email'), $request);

        return $this->successResponse(
            null,
            'Jika email Anda terdaftar, kami telah mengirim kode verifikasi. Silakan cek inbox — periksa juga folder Spam atau Sampah bila tidak muncul.'
        );
    }

    public function verify(VerifyResetOtpRequest $request): JsonResponse
    {
        $result = $this->service->verifyOtp(
            $request->input('email'),
            $request->input('otp')
        );

        return $this->successResponse([
            'reset_token' => $result['reset_token'],
            'expires_at' => $result['expires_at']->toIso8601String(),
        ], 'Kode OTP terverifikasi.');
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $this->service->resetPassword(
            $request->input('email'),
            $request->input('reset_token'),
            $request->input('password')
        );

        return $this->successResponse(
            null,
            'Kata sandi berhasil diubah. Semua sesi login telah diakhiri. Silakan masuk kembali dengan kata sandi baru Anda.'
        );
    }
}
