<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_bundles') || ! Schema::hasTable('product_bundle_items')) {
            return;
        }

        $rows = DB::table('product_bundles as pb')
            ->join('product_variants as v', 'v.id', '=', 'pb.bundle_variant_id')
            ->select('v.product_id as bundle_product_id', 'pb.component_variant_id', 'pb.qty')
            ->get();

        foreach ($rows as $row) {
            if ($row->bundle_product_id === null) {
                continue;
            }

            $exists = DB::table('product_bundle_items')
                ->where('bundle_product_id', $row->bundle_product_id)
                ->where('component_variant_id', $row->component_variant_id)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('product_bundle_items')->insert([
                'id' => (string) Str::orderedUuid(),
                'bundle_product_id' => $row->bundle_product_id,
                'component_variant_id' => $row->component_variant_id,
                'qty' => $row->qty,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {

    }
};
