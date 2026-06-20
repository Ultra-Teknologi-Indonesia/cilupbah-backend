<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\Channel;

class ProductPantauanResource extends JsonResource
{
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

        $tiktokChannelId = Channel::where('code', 'tiktok')->value('id');
        if (! $tiktokChannelId) {
            return null;
        }

        $channelCategory = DB::table('category_channel_mappings as ccm')
            ->join('channel_categories as cc', 'cc.id', '=', 'ccm.channel_category_id')
            ->where('ccm.category_id', $this->category_id)
            ->where('cc.channel_id', $tiktokChannelId)
            ->whereNotNull('cc.rules')
            ->select('cc.rules')
            ->first();

        if (! $channelCategory) {
            return null;
        }

        $rules = json_decode($channelCategory->rules, true);
        $parts = [];

        $certs = $rules['product_certifications'] ?? [];
        $requiredCerts = array_filter($certs, fn ($c) => $c['is_required'] ?? false);
        if (count($requiredCerts) > 0) {
            $parts[] = count($requiredCerts) . ' sertifikasi wajib';
        }

        if ($rules['size_chart']['is_required'] ?? false) {
            $parts[] = 'Size Chart';
        }

        if ($rules['manufacturer']['is_required'] ?? false) {
            $parts[] = 'Manufacturer';
        }

        if ($rules['package_dimension']['is_required'] ?? false) {
            $parts[] = 'Dimensi Paket';
        }

        return empty($parts) ? null : implode(', ', $parts);
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
