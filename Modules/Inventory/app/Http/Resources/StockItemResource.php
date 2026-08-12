<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Support\StockSummary;

class StockItemResource extends JsonResource
{
    public function __construct(
        $resource,
        protected ?int $transitQty = null,
        protected ?int $pickedNotPackedQty = null,
    ) {
        parent::__construct($resource);
    }

    public static function collectionWithTransit($resource): array
    {
        $items = collect($resource)->values();
        $itemIds = $items->pluck('id')->all();

        $transit = StockSummary::transitForItems($itemIds);
        $pickedNotPacked = StockSummary::pickedNotPackedForItems($itemIds);

        return $items
            ->map(fn ($item) => (new self(
                $item,
                (int) ($transit[$item->id] ?? 0),
                (int) ($pickedNotPacked[$item->id] ?? 0),
            ))->resolve())
            ->all();
    }

    public function toArray(Request $request): array
    {
        $inventories = $this->relationLoaded('inventories') ? $this->inventories : collect();

        $bundleDerived = ($this->relationLoaded('product')
            && (bool) $this->product?->is_bundle
            && $this->product?->relationLoaded('bundleItems'))
            ? \Modules\Product\Support\BundleStock::derive($this->product)
            : null;

        return [
            'item_id' => $this->id,
            'item_code' => $this->sku,
            'item_name' => $this->whenLoaded('product', fn () => $this->product?->name),
            'item_group_id' => $this->product_id,
            'is_bundle' => $this->whenLoaded('product', fn () => (bool) $this->product?->is_bundle, false),
            'variation_values' => $this->variationValues(),
            'stock_this' => $this->whenLoaded('product', fn () => (bool) $this->product?->is_stored, true),
            'average_cost' => $this->weightedAverageCost($inventories),
            'location_stocks' => $bundleDerived ? [] : $this->locationStocks($inventories),
            'total_stocks' => $bundleDerived
                ? [
                    'on_hand' => $bundleDerived['on_hand'],
                    'pending_placement' => 0,
                    'on_order' => 0,
                    'transit' => 0,
                    'available' => $bundleDerived['available'],

                    'picked_not_packed' => 0,
                    'actual' => $bundleDerived['on_hand'],
                ]
                : $this->totalStocks($inventories),
            'thumbnail' => $this->resolveThumbnail(),
        ];
    }

    protected function variationValues(): array
    {
        if (! $this->relationLoaded('options')) {
            return [];
        }

        return $this->options->map(fn ($opt) => [
            'label' => $opt->relationLoaded('attribute') ? $opt->attribute?->name : null,
            'value' => $opt->value,
        ])->values()->toArray();
    }

    protected function weightedAverageCost($inventories): string
    {
        if ($inventories->isEmpty()) {
            return '0.0000';
        }

        $totalQty = $inventories->sum('on_hand');
        if ($totalQty <= 0) {
            return number_format($inventories->avg('avg_cost') ?? 0, 4, '.', '');
        }

        $weightedSum = $inventories->sum(fn ($inv) => $inv->on_hand * $inv->avg_cost);

        return number_format($weightedSum / $totalQty, 4, '.', '');
    }

    protected function isPlaced($inv): bool
    {
        if ($inv->bin_id === null) {
            return false;
        }

        return $inv->relationLoaded('bin') && $inv->bin !== null && ! (bool) $inv->bin->is_inbound;
    }

    protected function locationStocks($inventories): array
    {
        if ($inventories->isEmpty()) {
            return [];
        }

        return $inventories
            ->groupBy('location_id')
            ->map(function ($rows, $locationId) {
                $placedOnHand = (int) $rows->filter(fn ($inv) => $this->isPlaced($inv))->sum('on_hand');
                $onOrder = (int) $rows->sum('on_order');
                $first = $rows->first();

                return [
                    'item_id' => $this->id,
                    'location_id' => $locationId,
                    'location_name' => $first && $first->relationLoaded('location') ? $first->location?->location_name : null,
                    'on_hand' => $placedOnHand,
                    'on_order' => $onOrder,
                    'available' => $placedOnHand - $onOrder,
                ];
            })
            ->values()
            ->toArray();
    }

    protected function totalStocks($inventories): array
    {
        $onHand = (int) $inventories->filter(fn ($inv) => $this->isPlaced($inv))->sum('on_hand');
        $pending = (int) $inventories->reject(fn ($inv) => $this->isPlaced($inv))->sum('on_hand');
        $onOrder = (int) $inventories->sum('on_order');

        return [
            'on_hand' => $onHand,
            'pending_placement' => $pending,
            'on_order' => $onOrder,
            'transit' => $this->transitQty ?? (int) (StockSummary::forItem($this->id)['transit'] ?? 0),
            'available' => $onHand - $onOrder,
            'picked_not_packed' => $this->pickedNotPacked(),

            'actual' => $onHand,
        ];
    }

    protected function pickedNotPacked(): int
    {
        if ($this->pickedNotPackedQty !== null) {
            return $this->pickedNotPackedQty;
        }

        return (int) (StockSummary::pickedNotPackedForItems([$this->id])[$this->id] ?? 0);
    }

    protected function resolveThumbnail(): ?string
    {
        if ($this->relationLoaded('media') && $this->media->isNotEmpty()) {
            $primary = $this->media->firstWhere('is_primary', true);

            return $primary ? $primary->url : $this->media->first()->url;
        }

        if ($this->relationLoaded('product') && $this->product?->relationLoaded('media') && $this->product->media->isNotEmpty()) {
            $primary = $this->product->media->firstWhere('is_primary', true);

            return $primary ? $primary->url : $this->product->media->first()->url;
        }

        return null;
    }
}
