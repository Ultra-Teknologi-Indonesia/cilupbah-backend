<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Support\StockSummary;

class InventoryStockResource extends JsonResource
{
    public function __construct($resource, protected ?int $pickedNotPackedQty = null)
    {
        parent::__construct($resource);
    }

    public static function collectionWithActual($resource): array
    {
        $rows = collect($resource)->values();
        $byBin = StockSummary::pickedNotPackedByBin($rows->pluck('item_id')->all());

        return $rows
            ->map(fn ($row) => (new self(
                $row,
                (int) ($byBin[$row->item_id][$row->bin_id] ?? 0),
            ))->resolve())
            ->all();
    }

    public function toArray(Request $request): array
    {
        $onHand = (int) $this->on_hand;
        $pickedNotPacked = $this->pickedNotPackedQty ?? 0;

        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'location_id' => $this->location_id,
            'location_name' => $this->whenLoaded('location', fn () => $this->location?->location_name),
            'bin_id' => $this->bin_id,
            'bin_code' => $this->whenLoaded('bin', fn () => $this->bin?->bin_final_code),
            'floor_code' => $this->whenLoaded('bin', fn () => $this->bin?->floor_code),
            'row_code' => $this->whenLoaded('bin', fn () => $this->bin?->row_code),
            'column_code' => $this->whenLoaded('bin', fn () => $this->bin?->column_code),
            'zone_id' => $this->whenLoaded('bin', fn () => $this->bin?->zone_id),
            'zone_code' => $this->whenLoaded('bin', fn () => $this->bin?->zone?->zone_code),
            'zone_name' => $this->whenLoaded('bin', fn () => $this->bin?->zone?->zone_name),
            'batch_no' => $this->batch_no,
            'serial_no' => $this->serial_no,
            'expired_date' => $this->expired_date,
            'on_hand' => $onHand,
            'on_order' => (int) $this->on_order,
            'available' => (int) $this->available,
            'picked_not_packed' => $pickedNotPacked,

            'actual' => $onHand,
            'avg_cost' => (float) $this->avg_cost,
        ];
    }
}
