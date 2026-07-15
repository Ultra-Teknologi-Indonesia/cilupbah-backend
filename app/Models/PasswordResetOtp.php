<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PasswordResetOtp extends Model
{
    protected $table = 'password_reset_otps';

    protected $fillable = [
        'email',
        'otp_hash',
        'reset_token_hash',
        'reset_token_expires_at',
        'attempts',
        'verified_at',
        'used_at',
        'expires_at',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'used_at' => 'datetime',
            'reset_token_expires_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}
