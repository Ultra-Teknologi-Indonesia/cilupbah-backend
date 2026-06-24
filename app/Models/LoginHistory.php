<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoginHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'agent_device',
        'agent_os',
        'agent_browser',
        'ip_address',
        'location_country',
        'location_region',
        'location_city',
        'location_district',
        'location_village',
        'location_lat',
        'location_lon',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'location_lat' => 'float',
            'location_lon' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function recordLogin(string $userId, string $ipAddress, string $userAgent): self
    {
        $parsed = self::parseUserAgent($userAgent);

        return self::create([
            'user_id' => $userId,
            'agent_device' => $parsed['device'],
            'agent_os' => $parsed['os'],
            'agent_browser' => $parsed['browser'],
            'ip_address' => $ipAddress,
        ]);
    }

    private static function parseUserAgent(string $ua): array
    {
        $browser = 'Other';
        if (str_contains($ua, 'Edg/')) {
            $browser = 'Edge';
        } elseif (str_contains($ua, 'OPR/') || str_contains($ua, 'Opera')) {
            $browser = 'Opera';
        } elseif (preg_match('/Chrome\/[\d.]+/', $ua)) {
            $browser = 'Chrome';
        } elseif (str_contains($ua, 'Firefox/')) {
            $browser = 'Firefox';
        } elseif (str_contains($ua, 'Safari/') && str_contains($ua, 'Version/')) {
            $browser = 'Safari';
        }

        $os = 'Other';
        if (str_contains($ua, 'Windows')) {
            $os = 'Windows';
        } elseif (str_contains($ua, 'Macintosh') || str_contains($ua, 'Mac OS')) {
            $os = 'macOS';
        } elseif (str_contains($ua, 'Android')) {
            $os = 'Android';
        } elseif (str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')) {
            $os = 'iOS';
        } elseif (str_contains($ua, 'Linux')) {
            $os = 'Linux';
        }

        $device = 'Other';
        if (str_contains($ua, 'Mobile') || str_contains($ua, 'iPhone')) {
            $device = 'Mobile';
        } elseif (str_contains($ua, 'iPad') || str_contains($ua, 'Tablet')) {
            $device = 'Tablet';
        } elseif (str_contains($ua, 'Windows') || str_contains($ua, 'Macintosh') || str_contains($ua, 'Linux')) {
            $device = 'Desktop';
        }

        return compact('browser', 'os', 'device');
    }
}
