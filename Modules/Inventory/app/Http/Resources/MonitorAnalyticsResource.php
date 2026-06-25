<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Baris tab analitik penjualan (Tidak Laku / Paling Laku / Perkiraan Habis).
 * Atribut last_sold/qty_sold/days_idle/avg_per_day/days_to_out berasal dari
 * selectRaw di MonitorStockRepository (sebagian nullable tergantung tab).
 */
class MonitorAnalyticsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $daysToOut = isset($this->days_to_out) && $this->days_to_out !== null
            ? (int) $this->days_to_out
            : null;

        $estimatedDate = $daysToOut !== null
            ? now()->addDays($daysToOut)->toDateString()
            : null;

        return [
            'item_id'        => $this->id,
            'sku'            => $this->sku,
            'product_name'   => $this->product_name ?? $this->whenLoaded('product', fn () => $this->product?->name),
            'brand_name'     => $this->whenLoaded('product', fn () => $this->product?->brand?->name),
            'variation_values' => $this->variationValues(),
            'thumbnail'      => $this->resolveThumbnail(),
            'on_hand'        => (int) ($this->total_on_hand ?? 0),
            'on_order'       => (int) ($this->total_on_order ?? 0),
            'available'      => (int) ($this->total_available ?? 0),
            'last_sold'      => $this->last_sold ? (string) $this->last_sold : null,
            'qty_sold'       => (int) ($this->qty_sold ?? 0),
            'days_idle'      => isset($this->days_idle) && $this->days_idle !== null ? (int) $this->days_idle : null,
            'avg_per_day'    => isset($this->avg_per_day) && $this->avg_per_day !== null ? (float) $this->avg_per_day : null,
            'days_to_out'    => $daysToOut,
            'estimated_date' => $estimatedDate,
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
