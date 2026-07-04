<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class StockReplenishmentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                  => $this->id,
            'status'              => $this->status,
            'from_location_id'    => $this->from_location_id,
            'to_location_id'      => $this->to_location_id,
            'from_location_name'  => $this->fromLocation?->location_name,
            'to_location_name'    => $this->toLocation?->location_name,
            'requested_by_user_id' => $this->requested_by_user_id,
            'requested_by_name'   => $this->requester?->name,
            'assignee_user_id'    => $this->assignee_user_id,
            'assignee_name'       => $this->assignee?->name,
            'requested_at'        => $this->requested_at,
            'accepted_at'         => $this->accepted_at,
            'rejected_at'         => $this->rejected_at,
            'done_at'             => $this->done_at,
            'reject_reason'       => $this->reject_reason,
            'note'                => $this->note,
            'items'               => $this->whenLoaded('items', fn () => $this->items->map(fn ($it) => [
                'id'      => $it->id,
                'item_id' => $it->item_id,
                'sku'     => $it->sku,
                'qty'     => (int) $it->qty,
                'reason'  => $it->reason,
            ])),
            'created_at'          => $this->created_at,
            'updated_at'          => $this->updated_at,
        ];
    }
}
