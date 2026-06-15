<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_media', function (Blueprint $table) {
            $table->uuid('media_uuid')->nullable()->after('variant_id');

            $table->string('url')->nullable()->change();

            $table->index('media_uuid');
            $table->foreign('media_uuid')->references('uuid')->on('media')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_media', function (Blueprint $table) {
            $table->dropForeign(['media_uuid']);
            $table->dropIndex(['media_uuid']);
            $table->dropColumn('media_uuid');
            $table->string('url')->nullable(false)->change();
        });
    }
};
