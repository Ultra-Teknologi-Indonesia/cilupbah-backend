<?php

namespace Modules\Outbound\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ShipmentListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $location = $this->relationLoaded('location') ? $this->location : null;

        return [
            'id' => $this->id,
            'shipment_no' => $this->shipment_no,
            'location_id' => $this->location_id,
            'location' => $location ? [
                'id' => $location->id,
                'location_name' => $location->location_name,
            ] : null,
            'courier_code' => $this->courier_code,
            'courier_name' => $this->courier_name,
            'shipment_type' => $this->shipment_type,
            'shipment_date' => $this->shipment_date,
            'status' => $this->status,
            'handed_over_at' => $this->handed_over_at,
            'orders_count' => (int) ($this->orders_count ?? 0),
            'total_weight_gram' => (float) ($this->total_weight_gram ?? 0),
            'has_instant' => (bool) $this->has_instant,
            'created_at' => $this->created_at,
            'driver_name' => $this->driver_name,
            'driver_phone' => $this->driver_phone,
            'driver_call_status' => $this->driver_call_status,
            'driver_called_at' => $this->driver_called_at,
        ];
    }
}
