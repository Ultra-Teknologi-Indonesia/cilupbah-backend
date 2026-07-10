<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{

    public function up(): void
    {
        $rows = DB::table('putaways as p')
            ->join('inbounds as i', 'i.id', '=', 'p.source_id')
            ->leftJoin('putaway_sources as ps', function ($join) {
                $join->on('ps.putaway_id', '=', 'p.id')
                    ->on('ps.inbound_id', '=', 'p.source_id');
            })
            ->where('p.source_type', 'INBOUND')
            ->whereNull('ps.id')
            ->get(['p.id as putaway_id', 'p.source_id as inbound_id']);

        $now = now();
        foreach ($rows->chunk(500) as $chunk) {
            DB::table('putaway_sources')->insert(
                $chunk->map(fn ($r) => [
                    'id'         => (string) Str::orderedUuid(),
                    'putaway_id' => $r->putaway_id,
                    'inbound_id' => $r->inbound_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all()
            );
        }
    }

    public function down(): void
    {

    }
};
