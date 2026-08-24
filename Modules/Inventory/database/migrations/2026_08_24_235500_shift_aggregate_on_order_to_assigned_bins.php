<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $aggregates = DB::table('inventories')
                ->whereNull('bin_id')
                ->where('on_order', '>', 0)
                ->get();

            foreach ($aggregates as $agg) {
                $onOrder = (int) $agg->on_order;
                if ($onOrder <= 0) {
                    continue;
                }

                // 1. Find assigned rack from sku_rack_assignments
                $targetBinId = DB::table('sku_rack_assignments')
                    ->where('item_id', $agg->item_id)
                    ->where('location_id', $agg->location_id)
                    ->value('bin_id');

                // 2. If not found, find placed bin with on_hand > 0
                if (! $targetBinId) {
                    $targetBinId = DB::table('inventories as i')
                        ->join('location_bins as b', 'b.id', '=', 'i.bin_id')
                        ->where('i.item_id', $agg->item_id)
                        ->where('i.location_id', $agg->location_id)
                        ->where('b.is_inbound', false)
                        ->where('i.on_hand', '>', 0)
                        ->orderByDesc('i.on_hand')
                        ->value('i.bin_id');
                }

                // 3. If still not found, find any placed bin
                if (! $targetBinId) {
                    $targetBinId = DB::table('inventories as i')
                        ->join('location_bins as b', 'b.id', '=', 'i.bin_id')
                        ->where('i.item_id', $agg->item_id)
                        ->where('i.location_id', $agg->location_id)
                        ->where('b.is_inbound', false)
                        ->value('i.bin_id');
                }

                if ($targetBinId) {
                    $existingBinRow = DB::table('inventories')
                        ->where('item_id', $agg->item_id)
                        ->where('location_id', $agg->location_id)
                        ->where('bin_id', $targetBinId)
                        ->first();

                    if ($existingBinRow) {
                        $newOnOrder = (int) $existingBinRow->on_order + $onOrder;
                        $newAvail = max(0, (int) $existingBinRow->on_hand - $newOnOrder);
                        DB::table('inventories')
                            ->where('id', $existingBinRow->id)
                            ->update([
                                'on_order' => $newOnOrder,
                                'available' => $newAvail,
                                'updated_at' => now(),
                            ]);
                    } else {
                        DB::table('inventories')->insert([
                            'id' => (string) Str::uuid(),
                            'item_id' => $agg->item_id,
                            'location_id' => $agg->location_id,
                            'bin_id' => $targetBinId,
                            'on_hand' => 0,
                            'on_order' => $onOrder,
                            'available' => 0,
                            'avg_cost' => $agg->avg_cost ?? 0,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    DB::table('inventories')
                        ->where('id', $agg->id)
                        ->update([
                            'on_order' => 0,
                            'available' => 0,
                            'updated_at' => now(),
                        ]);
                }
            }
        });
    }

    public function down(): void
    {
    }
};
