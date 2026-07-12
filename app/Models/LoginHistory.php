<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Http;
use Jenssegers\Agent\Agent;

class LoginHistory extends Model
{
    public $timestamps = false;

    public const CLIENT_WEB = 'web';
    public const CLIENT_MOBILE = 'mobile';

    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'client_type',
        'status',
        'token_id',
        'email_attempt',
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
            'token_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function recordLogin(
        string $userId,
        string $ipAddress,
        string $userAgent,
        string $clientType = self::CLIENT_WEB,
        ?int $tokenId = null
    ): self {
        [$device, $os, $browser] = self::parseAgent($userAgent, $clientType);
        $location = self::resolveLocation($ipAddress);

        return self::create([
            'user_id' => $userId,
            'client_type' => $clientType,
            'status' => self::STATUS_SUCCESS,
            'token_id' => $tokenId,
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

    public static function recordFailed(
        string $emailAttempt,
        string $ipAddress,
        string $userAgent,
        string $clientType = self::CLIENT_WEB
    ): self {
        [$device, $os, $browser] = self::parseAgent($userAgent, $clientType);
        $location = self::resolveLocation($ipAddress);

        return self::create([
            'user_id' => null,
            'client_type' => $clientType,
            'status' => self::STATUS_FAILED,
            'email_attempt' => $emailAttempt,
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

    private static function parseAgent(string $userAgent, string $clientType): array
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

        if ($clientType === self::CLIENT_MOBILE) {
            if ($device === 'Desktop' || $device === 'Other') {
                $device = 'Mobile';
            }
            if ($browser === 'Other') {
                $browser = 'Cilupbah App';
            }
        }

        return [$device, $os, $browser];
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

        }

        return $default;
    }
}
