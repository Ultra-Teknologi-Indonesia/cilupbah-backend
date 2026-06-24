<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Http;
use Jenssegers\Agent\Agent;

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
        $agent = new Agent();
        $agent->setUserAgent($userAgent);

        $browser = $agent->browser() ?: 'Other';
        $os = $agent->platform() ?: 'Other';

        $device = 'Desktop';
        if ($agent->isPhone()) {
            $device = 'Mobile';
        } elseif ($agent->isTablet()) {
            $device = 'Tablet';
        }

        $location = self::resolveLocation($ipAddress);

        return self::create([
            'user_id' => $userId,
            'agent_device' => $device,
            'agent_os' => $os,
            'agent_browser' => $browser,
            'ip_address' => $ipAddress,
            'location_country' => $location['country'],
            'location_region' => $location['region'],
            'location_city' => $location['city'],
            'location_lat' => $location['lat'],
            'location_lon' => $location['lon'],
        ]);
    }

    private static function resolveLocation(string $ip): array
    {
        $default = [
            'country' => '-',
            'region' => '-',
            'city' => '-',
            'lat' => null,
            'lon' => null,
        ];

        try {
            $response = Http::timeout(3)->get("http://ip-api.com/json/{$ip}", [
                'fields' => 'status,country,regionName,city,lat,lon',
            ]);

            if ($response->successful() && $response->json('status') === 'success') {
                return [
                    'country' => $response->json('country', '-'),
                    'region' => $response->json('regionName', '-'),
                    'city' => $response->json('city', '-'),
                    'lat' => $response->json('lat'),
                    'lon' => $response->json('lon'),
                ];
            }
        } catch (\Throwable) {
            // Silently fail — location is non-critical
        }

        return $default;
    }
}
