<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {

        $soloIds = DB::table('category_channel_mappings')
            ->select('channel_category_id')
            ->groupBy('channel_category_id')
            ->havingRaw('count(*) = 1')
            ->pluck('channel_category_id');

        if ($soloIds->isNotEmpty()) {
            DB::table('category_channel_mappings')
                ->whereIn('channel_category_id', $soloIds)
                ->update(['is_pull_default' => true]);
        }
    }

    public function down(): void
    {
        DB::table('category_channel_mappings')
            ->update(['is_pull_default' => false]);
    }
};
