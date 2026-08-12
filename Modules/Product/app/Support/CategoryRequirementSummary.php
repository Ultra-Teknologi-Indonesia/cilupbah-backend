<?php

namespace Modules\Product\Support;

use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\Channel;

class CategoryRequirementSummary
{
    public const CHANNEL_CODE = 'tiktok';

    public static function forCategories(array $categoryIds): array
    {
        $categoryIds = array_values(array_filter(array_unique($categoryIds)));

        if (empty($categoryIds)) {
            return [];
        }

        $channelId = Channel::where('code', self::CHANNEL_CODE)->value('id');

        if (! $channelId) {
            return [];
        }

        $rows = DB::table('category_channel_mappings as ccm')
            ->join('channel_categories as cc', 'cc.id', '=', 'ccm.channel_category_id')
            ->whereIn('ccm.category_id', $categoryIds)
            ->where('cc.channel_id', $channelId)
            ->whereNotNull('cc.rules')
            ->select('ccm.category_id', 'cc.rules')
            ->get();

        $summaries = [];

        foreach ($rows as $row) {
            if (array_key_exists($row->category_id, $summaries)) {
                continue;
            }

            $summaries[$row->category_id] = self::summarize(json_decode($row->rules, true));
        }

        return $summaries;
    }

    public static function summarize(?array $rules): ?string
    {
        if (empty($rules)) {
            return null;
        }

        $parts = [];

        $certs = $rules['product_certifications'] ?? [];
        $requiredCerts = array_filter($certs, fn ($c) => $c['is_required'] ?? false);
        if (count($requiredCerts) > 0) {
            $parts[] = count($requiredCerts).' sertifikasi wajib';
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
}
