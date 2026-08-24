<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $inbounds = DB::table('inbounds')
                ->where('type', 'SALES_RETURN')
                ->where('status', '!=', 'CANCELLED')
                ->get();

            $systemUserId = DB::table('users')->value('id') ?? (string) Str::uuid();

            foreach ($inbounds as $inbound) {
                $items = DB::table('inbound_items')
                    ->where('inbound_id', $inbound->id)
                    ->get();

                $defaultBin = DB::table('location_bins')
                    ->where('location_id', $inbound->location_id)
                    ->where('is_inbound', true)
                    ->first();

                if (! $defaultBin) {
                    $defaultBin = DB::table('location_bins')
                        ->where('location_id', $inbound->location_id)
                        ->first();
                }

                $hasChanges = false;

                foreach ($items as $item) {
                    $expected = (int) ($item->expected_qty ?? 0);
                    $received = (int) ($item->received_qty ?? 0);

                    if ($received < $expected && $expected > 0) {
                        $diff = $expected - $received;

                        DB::table('inbound_items')
                            ->where('id', $item->id)
                            ->update([
                                'received_qty' => $expected,
                                'updated_at' => now(),
                            ]);

                        if ($defaultBin) {
                            DB::table('inbound_receipts')->insert([
                                'id' => (string) Str::uuid(),
                                'inbound_item_id' => $item->id,
                                'qty' => $diff,
                                'bin_id' => $defaultBin->id,
                                'condition' => 'GOOD',
                                'received_by_user_id' => $systemUserId,
                                'received_date' => now(),
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }

                        $hasChanges = true;
                    }
                }

                if ($hasChanges || $inbound->status === 'DRAFT') {
                    DB::table('inbounds')
                        ->where('id', $inbound->id)
                        ->update([
                            'status' => 'RECEIVED',
                            'once_received_at' => $inbound->once_received_at ?? now(),
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
