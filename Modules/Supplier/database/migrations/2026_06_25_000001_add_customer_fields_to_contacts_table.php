<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('source', 50)->nullable()->after('account_payable');
            $table->string('nationality', 100)->nullable()->default('Indonesia')->after('source');
            $table->date('birth_date')->nullable()->after('nationality');
            $table->boolean('is_dropshipper')->default(false)->after('birth_date');
            $table->boolean('is_reseller')->default(false)->after('is_dropshipper');
            $table->string('tax_type', 20)->nullable()->default('NON_PKP')->after('is_reseller');
            $table->string('nik_photo_path')->nullable()->after('tax_type');
            $table->string('npwp_photo_path')->nullable()->after('nik_photo_path');
            $table->boolean('npwp_use_different')->default(false)->after('npwp_photo_path');
            $table->string('npwp_name')->nullable()->after('npwp_use_different');
            $table->text('npwp_address')->nullable()->after('npwp_name');
            $table->foreignUuid('salesman_id')->nullable()->after('npwp_address')->constrained('salesmen')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropForeign(['salesman_id']);
            $table->dropColumn([
                'source', 'nationality', 'birth_date', 'is_dropshipper', 'is_reseller',
                'tax_type', 'nik_photo_path', 'npwp_photo_path', 'npwp_use_different',
                'npwp_name', 'npwp_address', 'salesman_id',
            ]);
        });
    }
};
