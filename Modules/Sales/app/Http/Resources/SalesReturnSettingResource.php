<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sales\Models\SalesReturnSetting;

class SalesReturnSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'auto_accept' => (bool) $this->auto_accept,
            'auto_receive' => (bool) $this->auto_receive,
            'default_restock_location_id' => $this->default_restock_location_id,
            'allowed_conditions' => $this->allowed_conditions ?: SalesReturnSetting::DEFAULT_CONDITIONS,
            'allowed_refund_methods' => $this->allowed_refund_methods ?: SalesReturnSetting::DEFAULT_REFUND_METHODS,
            'return_validity_days' => $this->return_validity_days,
            'updated_at' => $this->updated_at,
        ];
    }
}
