<?php

namespace Modules\Sales\Http\Resources;

use App\Support\ActorName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesReturnReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $settlement = $this->settlement;
        $refunds = $settlement?->refunds ?? collect();

        return [
            'id' => $this->id,
            'return_number' => $this->return_number,
            'order_id' => $this->order_id,
            'order_no' => $this->order?->salesorder_no,
            'channel_order_no' => $this->order?->channel_order_no,
            'channel_shop_id' => $this->channel_shop_id,
            'channel_return_id' => $this->channel_return_id,
            'location_id' => $this->location_id,
            'location_name' => $this->location?->location_name,
            'customer_name' => $this->customer_name ?? $this->order?->customer_name,
            'source' => $this->source,
            'status' => $this->status,
            'reason' => $this->reason,
            'reason_category' => $this->reason_category,
            'notes' => $this->notes,
            'processed_by' => ActorName::resolve($this->processed_by),
            'processed_at' => optional($this->processed_at)?->format('Y-m-d H:i:s'),
            'created_at' => optional($this->created_at)?->format('Y-m-d H:i:s'),

            'marketplace_decision' => $this->marketplace_decision,
            'marketplace_decision_label' => $this->marketplace_decision_label,
            'marketplace_raw_status' => $this->marketplace_raw_status,
            'marketplace_raw_status_label' => $this->marketplace_raw_status_label,
            'channel_reason_text' => $this->channel_reason_text ?? $this->channel_reason_code,
            'refund_amount' => $this->refund_amount !== null ? (float) $this->refund_amount : null,
            'shipping_fee_original' => $this->shipping_fee_original !== null ? (float) $this->shipping_fee_original : null,
            'shipping_fee_return' => $this->shipping_fee_return !== null ? (float) $this->shipping_fee_return : null,
            'return_tracking_number' => $this->return_tracking_number,
            'return_carrier' => $this->return_carrier,
            'return_shipped_at' => optional($this->return_shipped_at)?->format('Y-m-d H:i:s'),

            'settlement_number' => $settlement?->settlement_number,
            'settlement_status' => $settlement?->status,
            'settlement_total' => $settlement ? (float) $settlement->total_amount : 0,
            'refund_total' => (float) $refunds->sum('amount'),
            'refund_count' => $refunds->count(),
            'refund_methods' => $refunds->pluck('refund_method')->unique()->values()->all(),
            'refund_last_date' => optional($refunds->max('refund_date'))?->format('Y-m-d'),
        ];
    }
}
