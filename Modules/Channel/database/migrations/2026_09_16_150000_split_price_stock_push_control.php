<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('channel_shops', 'price_push_enabled')) {
            Schema::table('channel_shops', function (Blueprint $table): void {
                $table->boolean('price_push_enabled')->default(false)->after('stock_push_enabled');
            });
        }

        DB::table('channel_shops')->update([
            'price_push_enabled' => false,
        ]);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE channel_shops ALTER COLUMN stock_push_enabled SET DEFAULT FALSE');
        } else {
            Schema::table('channel_shops', function (Blueprint $table): void {
                $table->boolean('stock_push_enabled')->default(false)->change();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE channel_shops ALTER COLUMN stock_push_enabled SET DEFAULT TRUE');
        } else {
            Schema::table('channel_shops', function (Blueprint $table): void {
                $table->boolean('stock_push_enabled')->default(true)->change();
            });
        }

        if (Schema::hasColumn('channel_shops', 'price_push_enabled')) {
            Schema::table('channel_shops', function (Blueprint $table): void {
                $table->dropColumn('price_push_enabled');
            });
        }
    }
};
