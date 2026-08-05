<?php

namespace Modules\Webhook\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebhookDeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subscription_id' => $this->subscription_id,
            'event' => $this->event,
            'event_id' => $this->event_id,
            'status' => $this->status,
            'status_code' => $this->status_code,
            'attempts' => $this->attempts,
            'last_error' => \App\Support\FriendlyError::generic($this->last_error, 'Endpoint tujuan menolak atau tidak merespons.'),
            'payload' => $this->payload,
            'delivered_at' => $this->delivered_at,
            'created_at' => $this->created_at,
        ];
    }
}
