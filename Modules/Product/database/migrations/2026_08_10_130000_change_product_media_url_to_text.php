<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE product_media ALTER COLUMN url TYPE text');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE product_media ALTER COLUMN url TYPE varchar(255)');
    }
};
