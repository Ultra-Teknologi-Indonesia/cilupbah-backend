<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('pic_title')->nullable()->after('contact_person');
            $table->string('fax', 30)->nullable()->after('mobile');
            $table->string('nik', 50)->nullable()->after('tax_id');
            $table->text('shipping_address')->nullable()->after('postal_code');
            $table->string('shipping_province')->nullable()->after('shipping_address');
            $table->string('shipping_postal_code', 10)->nullable()->after('shipping_province');
            $table->boolean('shipping_same_as_billing')->default(true)->after('shipping_postal_code');
            $table->decimal('latitude', 10, 7)->nullable()->after('shipping_same_as_billing');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->integer('payment_term')->nullable()->default(null)->change();
        });

        Schema::table('contact_categories', function (Blueprint $table) {
            $table->string('code', 20)->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn([
                'pic_title', 'fax', 'nik',
                'shipping_address', 'shipping_province', 'shipping_postal_code',
                'shipping_same_as_billing', 'latitude', 'longitude',
            ]);
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->string('payment_term', 50)->default('NET30')->change();
        });

        Schema::table('contact_categories', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }
};
