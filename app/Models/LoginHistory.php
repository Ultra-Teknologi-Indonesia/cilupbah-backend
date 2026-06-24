<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

        return self::create([
            'user_id' => $userId,
            'agent_device' => $device,
            'agent_os' => $os,
            'agent_browser' => $browser,
            'ip_address' => $ipAddress,
        ]);
    }
}
