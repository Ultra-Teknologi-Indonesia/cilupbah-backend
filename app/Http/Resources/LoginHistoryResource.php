<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoginHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user_id_snapshot' => $this->user_id_snapshot,
            'user_name' => $this->user?->name ?? $this->user_name,
            'user_email' => $this->user?->email ?? $this->user_email,
            'agent_device' => $this->agent_device,
            'agent_os' => $this->agent_os,
            'agent_browser' => $this->agent_browser,
            'ip_address' => $this->ip_address,
            'location_country' => $this->location_country,
            'location_region' => $this->location_region,
            'location_city' => $this->location_city,
            'location_district' => $this->location_district,
            'location_village' => $this->location_village,
            'location_lat' => $this->location_lat,
            'location_lon' => $this->location_lon,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
