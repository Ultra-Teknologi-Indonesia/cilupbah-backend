<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fase B — atribut form untuk satu kategori Level-2 (paling spesifik):
 * spesifikasi (type=spec) + jenis varian (type=sales), lengkap status per channel.
 */
class CategoryFormAttributeController extends Controller
{
    use ApiResponse;

    public function show($categoryId)
    {
        $category = DB::table('categories')->where('id', $categoryId)->first();
        if (! $category) {
            return $this->errorResponse('Kategori tidak ditemukan', 404);
        }

        // Hanya kategori paling spesifik (tanpa sub-kategori) yang punya atribut form.
        if (DB::table('categories')->where('parent_id', $categoryId)->exists()) {
            return $this->errorResponse('Pilih kategori paling spesifik (kategori tanpa sub-kategori).', 422);
        }

        $catAttrs = DB::table('category_attributes as cga')
            ->join('attributes as a', 'a.id', '=', 'cga.attribute_id')
            ->where('cga.category_id', $categoryId)
            ->get(['a.id', 'a.name', 'a.type', 'cga.is_required']);

        $attrIds = $catAttrs->pluck('id')->all() ?: [-1];

        $options = DB::table('attribute_options')
            ->whereIn('attribute_id', $attrIds)
            ->get(['id', 'attribute_id', 'value'])
            ->groupBy('attribute_id');

        // Status per channel: mapped + wajib di channel itu.
        $channelStatus = DB::table('attribute_channel_mappings as m')
            ->join('channel_attributes as ca', 'ca.id', '=', 'm.channel_attribute_id')
            ->join('channel_categories as cc', 'cc.id', '=', 'ca.channel_category_id')
            ->join('channels as ch', 'ch.id', '=', 'cc.channel_id')
            ->whereIn('m.attribute_id', $attrIds)
            ->get(['m.attribute_id', 'ch.code', 'ca.is_required'])
            ->groupBy('attribute_id');

        $build = function ($a) use ($options, $channelStatus) {
            $channels = [];
            foreach ($channelStatus->get($a->id, collect()) as $row) {
                $channels[$row->code] = ['mapped' => true, 'required' => (bool) $row->is_required];
            }

            return [
                'attribute_id' => (int) $a->id,
                'name' => $a->name,
                'is_required' => (bool) $a->is_required,
                'options' => ($options->get($a->id, collect()))
                    ->map(fn ($o) => ['id' => (int) $o->id, 'value' => $o->value])->values(),
                'channels' => (object) $channels,
            ];
        };

        return $this->successResponse([
            'specifications' => $catAttrs->where('type', 'spec')->map($build)->values(),
            'variant_types' => $catAttrs->where('type', 'sales')->map($build)->values(),
        ], 'Atribut form kategori berhasil diambil.');
    }
}
