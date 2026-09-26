<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OrderAuditReportRowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->audit_id,
            'channel' => (string) $this->channel,
            'shop_id' => $this->shop_id !== null ? (string) $this->shop_id : null,
            'shop_name' => $this->shop_name,
            'order_reference' => (string) $this->order_reference,
            'marketplace_status' => $this->marketplace_status,
            'wms_order_id' => $this->wms_id !== null ? (string) $this->wms_id : null,
            'internal_order_no' => $this->internal_order_no,
            'internal_status' => $this->internal_status,
            'wms_status' => $this->wms_status,
            'channel_status_raw' => $this->channel_status_raw,
            'match_state' => $this->match_state,
            'inbox_status' => $this->inbox_status,
            'attempts' => (int) $this->attempts,
            'error' => $this->inbox_error,
            'latest_received_at' => $this->latest_received_at,
            'wms_transaction_date' => $this->wms_transaction_date,
            'wms_updated_at' => $this->wms_updated_at,
        ];
    }
}
