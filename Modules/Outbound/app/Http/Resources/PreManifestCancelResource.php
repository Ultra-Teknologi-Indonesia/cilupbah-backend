<?php

namespace Modules\Outbound\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PreManifestCancelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'salesorder_no' => $this->salesorder_no,
            'channel_order_no' => $this->channel_order_no,
            'customer_name' => $this->customer_name,
            'source' => $this->source,
            'transaction_date' => $this->transaction_date,
            'tracking_number' => $this->tracking_number,
            'channel_status' => $this->channel_status,
            'cancel_reason' => $this->cancel_reason,
            'cancel_accepted_at' => $this->cancel_accepted_at,
        ];
    }
}
