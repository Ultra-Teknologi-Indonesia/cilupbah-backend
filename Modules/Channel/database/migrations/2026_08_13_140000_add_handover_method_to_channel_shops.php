<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::table('channel_shops', function (Blueprint $table) {
            $table->string('handover_method', 16)
                ->default('dropoff')
                ->after('fulfillment_handover_at');
        });

        DB::statement(
            "ALTER TABLE channel_shops
             ADD CONSTRAINT channel_shops_handover_method_check
             CHECK (handover_method IN ('dropoff', 'pickup')) NOT VALID"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE channel_shops DROP CONSTRAINT IF EXISTS channel_shops_handover_method_check');

        Schema::table('channel_shops', function (Blueprint $table) {
            $table->dropColumn('handover_method');
        });
    }
};
