<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class StockReplenishmentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'source' => $this->source,
            'batch_key' => $this->batch_key,
            'from_location_id' => $this->from_location_id,
            'to_location_id' => $this->to_location_id,
            'from_location_name' => $this->fromLocation?->location_name,
            'to_location_name' => $this->toLocation?->location_name,
            'requested_by_user_id' => $this->requested_by_user_id,
            'requested_by_name' => $this->requester?->name,
            'rejected_by_user_id' => $this->rejected_by_user_id,
            'rejected_by_name' => $this->rejecter?->name,
            'assignee_user_id' => $this->assignee_user_id,
            'assignee_name' => $this->assignee?->name,
            'transfer_out_id' => $this->transfer_out_id,
            'transfer_out_number' => $this->transferOut?->transfer_number,
            'transfer_out_status' => $this->transferOut?->status,
            'requested_at' => $this->requested_at,
            'accepted_at' => $this->accepted_at,
            'rejected_at' => $this->rejected_at,
            'done_at' => $this->done_at,
            'last_reconciled_at' => $this->last_reconciled_at,
            'cancelled_at' => $this->cancelled_at,
            'reject_reason' => $this->reject_reason,
            'cancel_reason' => $this->cancel_reason,
            'note' => $this->note,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($it) => [
                'id' => $it->id,
                'item_id' => $it->item_id,
                'sku' => $it->sku,
                'product_name' => $it->variant?->product?->name,
                'thumbnail_url' => self::resolveThumbnail($it->variant),
                'qty' => (int) $it->qty,
                'demand_qty' => (int) $it->demand_qty,
                'available_qty' => (int) $it->available_qty,
                'in_flight_qty' => (int) $it->in_flight_qty,
                'suggested_qty' => (int) $it->suggested_qty,
                'reason' => $it->reason,
                'reason_detail' => [
                    'type' => 'stock_shortage',
                    'label' => 'Kekurangan stok dari pesanan aktif',
                    'demand_qty' => (int) $it->demand_qty,
                    'available_qty' => (int) $it->available_qty,
                    'in_flight_qty' => (int) $it->in_flight_qty,
                    'suggested_qty' => (int) $it->suggested_qty,
                ],
            ])),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'items_count' => $this->items_count,
            'items_qty' => (int) ($this->items_sum_qty ?? 0),
        ];
    }

    private static function resolveThumbnail($variant): ?string
    {
        if (! $variant) {
            return null;
        }

        $variantMedia = $variant->media?->firstWhere('media_type', 'image')
            ?? $variant->media?->first();
        if ($variantMedia?->url) {
            return $variantMedia->url;
        }

        $productMedia = $variant->product?->media?->firstWhere('media_type', 'image')
            ?? $variant->product?->media?->first();

        return $productMedia?->url;
    }
}
