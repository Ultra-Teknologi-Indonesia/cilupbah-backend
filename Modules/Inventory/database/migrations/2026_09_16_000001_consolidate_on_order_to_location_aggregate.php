<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $groups = DB::table('inventories')
                ->where('on_order', '>', 0)
                ->select('item_id', 'location_id')
                ->selectRaw('SUM(on_order) AS reserved_qty')
                ->groupBy('item_id', 'location_id')
                ->get();

            foreach ($groups as $group) {
                $reservedQty = (int) $group->reserved_qty;
                if ($reservedQty <= 0) {
                    continue;
                }

                $aggregate = DB::table('inventories')
                    ->where('item_id', $group->item_id)
                    ->where('location_id', $group->location_id)
                    ->whereNull('bin_id')
                    ->where('batch_no', '')
                    ->where('serial_no', '')
                    ->lockForUpdate()
                    ->first();

                if ($aggregate) {
                    DB::table('inventories')
                        ->where('id', $aggregate->id)
                        ->update([
                            'on_order' => $reservedQty,
                            'available' => 0,
                            'updated_at' => now(),
                        ]);
                } else {
                    DB::table('inventories')->insert([
                        'id' => (string) Str::uuid(),
                        'item_id' => $group->item_id,
                        'location_id' => $group->location_id,
                        'bin_id' => null,
                        'batch_no' => '',
                        'serial_no' => '',
                        'on_hand' => 0,
                        'on_order' => $reservedQty,
                        'available' => 0,
                        'avg_cost' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('inventories')
                    ->where('item_id', $group->item_id)
                    ->where('location_id', $group->location_id)
                    ->whereNotNull('bin_id')
                    ->where('on_order', '>', 0)
                    ->update([
                        'on_order' => 0,
                        'available' => DB::raw('GREATEST(on_hand, 0)'),
                        'updated_at' => now(),
                    ]);
            }
        });
    }

    public function down(): void {}
};
