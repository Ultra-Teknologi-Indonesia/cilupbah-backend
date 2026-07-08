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
            'transfer_out_id'     => $this->transfer_out_id,
            'transfer_out_number' => $this->transferOut?->transfer_number,
            'transfer_out_status' => $this->transferOut?->status,
            'requested_at'        => $this->requested_at,
            'accepted_at'         => $this->accepted_at,
            'rejected_at'         => $this->rejected_at,
            'done_at'             => $this->done_at,
            'reject_reason'       => $this->reject_reason,
            'note'                => $this->note,
            'items'               => $this->whenLoaded('items', fn () => $this->items->map(fn ($it) => [
                'id'            => $it->id,
                'item_id'       => $it->item_id,
                'sku'           => $it->sku,
                'product_name'  => $it->variant?->product?->name,
                'thumbnail_url' => self::resolveThumbnail($it->variant),
                'qty'           => (int) $it->qty,
                'reason'        => $it->reason,
            ])),
            'created_at'          => $this->created_at,
            'updated_at'          => $this->updated_at,
        ];
    }

    /**
     * Ambil URL thumbnail: media varian (gambar) lebih dulu, fallback ke media produk.
     */
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
