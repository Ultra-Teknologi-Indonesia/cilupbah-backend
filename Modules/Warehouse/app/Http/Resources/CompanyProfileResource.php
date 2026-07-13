<?php

namespace Modules\Warehouse\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Warehouse\Services\CompanyProfileService;

class CompanyProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $service = app(CompanyProfileService::class);

        return [
            'legal_name' => $this->legal_name,
            'brand_name' => $this->brand_name,
            'npwp' => $this->npwp,
            'address' => $this->address,
            'city' => $this->city,
            'postal_code' => $this->postal_code,
            'phone' => $this->phone,
            'email' => $this->email,
            'logo_media_id' => $this->logo_media_id,
            'logo_url' => $service->mediaUrl($this->logo_media_id),
            'signature_media_id' => $this->signature_media_id,
            'signature_url' => $service->mediaUrl($this->signature_media_id),
            'updated_at' => $this->updated_at,
        ];
    }
}
