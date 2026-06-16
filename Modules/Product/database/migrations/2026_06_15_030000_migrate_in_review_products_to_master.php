<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{

    public function up(): void
    {
        DB::table('products')
            ->where('status', 'in_review')
            ->update(['status' => 'master', 'updated_at' => now()]);
    }

    public function down(): void
    {

    }
};
