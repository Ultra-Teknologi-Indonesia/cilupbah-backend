<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OrderAuditReportRowResource extends JsonResource
{
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
            'tracking_number' => $this->tracking_number,
            'shipping_label_status' => $this->shipping_label_status,
            'shipping_label_prepared_at' => $this->shipping_label_prepared_at,
            'bulk_label_batch_id' => $this->bulk_label_batch_id,
            'bulk_label_batch_status' => $this->bulk_label_batch_status,
            'bulk_label_item_status' => $this->bulk_label_item_status,
            'bulk_label_item_reason' => $this->bulk_label_item_reason,
            'bulk_label_batch_total' => $this->bulk_label_batch_total,
            'bulk_label_batch_done' => $this->bulk_label_batch_done,
            'bulk_label_batch_failed' => $this->bulk_label_batch_failed,
            'bulk_label_batch_created_at' => $this->bulk_label_batch_created_at,
            'bulk_label_batch_count' => $this->bulk_label_batch_count,
            'recovery_state' => $this->recovery_state,
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
