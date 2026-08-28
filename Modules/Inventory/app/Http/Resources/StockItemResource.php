<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Services\PurchaseCostService;
use Modules\Inventory\Support\StockSummary;
use Modules\Product\Support\BundleStock;

class StockItemResource extends JsonResource
{
    public function __construct(
        $resource,
        protected ?int $transitQty = null,
        protected ?int $pickedNotPackedQty = null,
        protected ?float $purchaseAverageCost = null,
        protected bool $purchaseCostResolved = false,
    ) {
        parent::__construct($resource);
    }

    public static function collectionWithTransit($resource): array
    {
        $items = collect($resource)->values();
        $itemIds = $items->pluck('id')->all();

        $transit = StockSummary::transitForItems($itemIds);
        $pickedNotPacked = StockSummary::pickedNotPackedForItems($itemIds);
        $purchaseCosts = app(PurchaseCostService::class)->averageForItemIds($itemIds);

        return $items
            ->map(fn ($item) => (new self(
                $item,
                (int) ($transit[$item->id] ?? 0),
                (int) ($pickedNotPacked[$item->id] ?? 0),
                $purchaseCosts[$item->id] ?? null,
                true,
            ))->resolve())
            ->all();
    }

    public function toArray(Request $request): array
    {
        $inventories = $this->relationLoaded('inventories') ? $this->inventories : collect();

        $bundleDerived = ($this->relationLoaded('product')
            && (bool) $this->product?->is_bundle
            && $this->product?->relationLoaded('bundleItems'))
            ? BundleStock::derive($this->product)
            : null;

        $averageCost = $this->resolvedAverageCost($inventories);

        return [
            'item_id' => $this->id,
            'item_code' => $this->sku,
            'item_name' => $this->whenLoaded('product', fn () => $this->product?->name),
            'item_group_id' => $this->product_id,
            'is_bundle' => $this->whenLoaded('product', fn () => (bool) $this->product?->is_bundle, false),
            'variation_values' => $this->variationValues(),
            'stock_this' => $this->whenLoaded('product', fn () => (bool) $this->product?->is_stored, true),
            'average_cost' => number_format($averageCost['value'], 4, '.', ''),
            'average_cost_source' => $averageCost['source'],
            'location_stocks' => $bundleDerived ? [] : $this->locationStocks($inventories),
            'total_stocks' => $bundleDerived
                ? [
                    'on_hand' => $bundleDerived['on_hand'],
                    'pending_placement' => 0,
                    'legacy_unassigned' => 0,
                    'physical_total' => $bundleDerived['on_hand'],
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

    protected function resolvedAverageCost($inventories): array
    {
        if (! $this->purchaseCostResolved) {
            $this->purchaseAverageCost = app(PurchaseCostService::class)
                ->averageForItem((string) $this->id);
            $this->purchaseCostResolved = true;
        }

        if ($this->purchaseAverageCost !== null && $this->purchaseAverageCost > 0) {
            return [
                'value' => round($this->purchaseAverageCost, 4),
                'source' => 'purchase_weighted_average',
            ];
        }

        $costedPositiveStock = $inventories->filter(
            fn ($inv) => (float) $inv->on_hand > 0 && (float) $inv->avg_cost > 0
        );
        $totalQty = (float) $costedPositiveStock->sum('on_hand');

        if ($totalQty <= 0) {
            return ['value' => 0.0, 'source' => 'unavailable'];
        }

        $weightedSum = (float) $costedPositiveStock->sum(
            fn ($inv) => (float) $inv->on_hand * (float) $inv->avg_cost
        );

        return [
            'value' => round(max(0.0, $weightedSum / $totalQty), 4),
            'source' => 'positive_inventory_fallback',
        ];
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
                $pendingPlacement = (int) $rows
                    ->filter(fn ($inv) => $inv->bin_id !== null
                        && $inv->relationLoaded('bin')
                        && $inv->bin !== null
                        && (bool) $inv->bin->is_inbound)
                    ->sum('on_hand');
                $legacyUnassigned = (int) $rows
                    ->filter(fn ($inv) => $inv->bin_id === null
                        || ($inv->relationLoaded('bin') && $inv->bin === null))
                    ->sum('on_hand');
                $onOrder = (int) $rows->sum('on_order');
                $first = $rows->first();

                return [
                    'item_id' => $this->id,
                    'location_id' => $locationId,
                    'location_name' => $first && $first->relationLoaded('location') ? $first->location?->location_name : null,
                    'on_hand' => $placedOnHand,
                    'pending_placement' => $pendingPlacement,
                    'legacy_unassigned' => $legacyUnassigned,
                    'physical_total' => $placedOnHand + $pendingPlacement,
                    'on_order' => $onOrder,
                    'available' => $placedOnHand - $onOrder,
                ];
            })
            ->values()
            ->toArray();
    }

    protected function totalStocks($inventories): array
    {
        $summary = StockSummary::partitionLoaded($inventories);
        $onHand = $summary['on_hand'];
        $onOrder = $summary['on_order'];

        return [
            'on_hand' => $onHand,
            'pending_placement' => $summary['pending_placement'],
            'legacy_unassigned' => $summary['legacy_unassigned'],
            'physical_total' => $summary['physical_total'],
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
