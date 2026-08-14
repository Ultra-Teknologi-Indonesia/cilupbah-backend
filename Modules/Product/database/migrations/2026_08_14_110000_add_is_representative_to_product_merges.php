<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_merges', function (Blueprint $table) {
            $table->boolean('is_representative')->default(false);
            $table->index(['product_id', 'is_representative'], 'idx_pm_product_rep');
            $table->index(['master_name', 'is_representative'], 'idx_pm_master_rep');
        });

        $merges = DB::table('product_merges')->select(['product_id', 'master_name'])->get();
        if ($merges->isNotEmpty()) {
            $productNames = DB::table('products')
                ->whereIn('id', $merges->pluck('product_id')->all())
                ->pluck('name', 'id')
                ->all();

            $byMaster = [];
            foreach ($merges as $m) {
                $byMaster[$m->master_name][] = $m->product_id;
            }

            $representativeIds = [];
            foreach ($byMaster as $masterName => $productIds) {
                sort($productIds);
                $repId = null;
                foreach ($productIds as $pid) {
                    if (($productNames[$pid] ?? null) === $masterName) {
                        $repId = $pid;
                        break;
                    }
                }
                $repId ??= $productIds[0];
                $representativeIds[] = $repId;
            }

            if (! empty($representativeIds)) {
                foreach (array_chunk($representativeIds, 500) as $chunk) {
                    DB::table('product_merges')
                        ->whereIn('product_id', $chunk)
                        ->update(['is_representative' => true]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('product_merges', function (Blueprint $table) {
            $table->dropIndex('idx_pm_product_rep');
            $table->dropIndex('idx_pm_master_rep');
            $table->dropColumn('is_representative');
        });
    }
};
