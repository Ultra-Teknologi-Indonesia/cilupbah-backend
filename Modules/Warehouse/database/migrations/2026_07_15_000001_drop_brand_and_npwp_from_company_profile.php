<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_profile', function (Blueprint $table) {
            if (Schema::hasColumn('company_profile', 'brand_name')) {
                $table->dropColumn('brand_name');
            }
            if (Schema::hasColumn('company_profile', 'npwp')) {
                $table->dropColumn('npwp');
            }
        });
    }

    public function down(): void
    {
        Schema::table('company_profile', function (Blueprint $table) {
            if (! Schema::hasColumn('company_profile', 'brand_name')) {
                $table->string('brand_name')->nullable();
            }
            if (! Schema::hasColumn('company_profile', 'npwp')) {
                $table->string('npwp')->nullable();
            }
        });
    }
};
