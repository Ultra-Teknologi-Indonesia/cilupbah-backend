<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_from_channel')->default(false)->after('is_consignment');
        });

        DB::table('products')
            ->whereIn('id', function ($query) {
                $query->select('product_id')
                    ->from('product_channel_mappings')
                    ->whereNotIn('product_id', function ($sub) {
                        $sub->select('product_id')
                            ->from('product_channel_drafts');
                    });
            })
            ->update(['is_from_channel' => true]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('is_from_channel');
        });
    }
};
