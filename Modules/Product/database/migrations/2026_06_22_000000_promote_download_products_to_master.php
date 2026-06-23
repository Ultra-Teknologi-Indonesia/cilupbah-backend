<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('products')
            ->where('status', 'download')
            ->update([
                'status' => 'master',
                'verified_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {

    }
};
