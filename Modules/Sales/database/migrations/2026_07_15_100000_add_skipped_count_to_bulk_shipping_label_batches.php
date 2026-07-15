<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bulk_shipping_label_batches', function (Blueprint $t) {
            $t->unsignedInteger('skipped_count')->default(0)->after('failed_count');
        });
    }

    public function down(): void
    {
        Schema::table('bulk_shipping_label_batches', function (Blueprint $t) {
            $t->dropColumn('skipped_count');
        });
    }
};
