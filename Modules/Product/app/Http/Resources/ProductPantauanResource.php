<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Product\Support\CategoryRequirementSummary;

class ProductPantauanResource extends JsonResource
{
    public function __construct($resource, protected ?array $requirementSummaries = null)
    {
        parent::__construct($resource);
    }

    public static function collectionWithRequirements($resource, ?Request $request = null): array
    {
        $items = collect($resource)->values();
        $summaries = CategoryRequirementSummary::forCategories($items->pluck('category_id')->all());

        return $items
            ->map(fn ($item) => (new self($item, $summaries))->resolve($request))
            ->all();
    }

    public function toArray(Request $request): array
    {
        return [
            'product_id' => $this->id,
            'product_name' => $this->name,
            'sku' => $this->sku,
            'primary_image' => $this->primaryImage(),
            'category_name' => $this->relationLoaded('category') ? ($this->category->name ?? null) : null,
            'product_type' => $this->is_bundle ? 'bundle' : ($this->is_consignment ? 'konsinyasi' : 'satuan'),
            'not_uploaded_count' => $this->not_uploaded_count !== null ? (int) $this->not_uploaded_count : null,
            'last_upload_error' => $this->last_upload_error ?? null,
            'mismatches' => $this->mismatches(),
            'requirements_summary' => $this->requirementsSummary(),
            'review_channels' => $this->when($this->relationLoaded('channelMappings'), function () {
                return $this->channelMappings->map(fn ($m) => [
                    'channel_code' => $m->channelShop?->channel?->code,
                    'shop_name' => $m->channelShop?->shop_name,
                    'sync_status' => $m->sync_status,
                    'error_message' => $m->error_message,
                    'reviewed_at' => $m->reviewed_at?->toIso8601String(),
                ])->values()->all();
            }),
        ];
    }

    protected function mismatches(): array
    {
        if (! $this->relationLoaded('channelValidations')) {
            return [];
        }

        return $this->channelValidations->map(fn ($v) => [
            'channel_code' => $v->relationLoaded('channel') ? ($v->channel->code ?? null) : null,
            'attribute_status' => $v->attribute_status,
            'attribute_issues' => $v->attribute_issues ?? [],
            'price_status' => $v->price_status,
            'price_issues' => $v->price_issues ?? [],
            'sku_status' => $v->sku_status,
            'sku_issues' => $v->sku_issues ?? [],
        ])->values()->all();
    }

    protected function requirementsSummary(): ?string
    {
        if (! $this->category_id) {
            return null;
        }

        $summaries = $this->requirementSummaries
            ?? CategoryRequirementSummary::forCategories([$this->category_id]);

        return $summaries[$this->category_id] ?? null;
    }

    protected function primaryImage(): ?string
    {
        if (! $this->relationLoaded('media')) {
            return null;
        }

        $media = $this->media;
        $primary = $media->whereNull('variant_id')->firstWhere('is_primary', true)
            ?? $media->whereNull('variant_id')->first()
            ?? $media->first();

        return $primary->url ?? null;
    }
}
