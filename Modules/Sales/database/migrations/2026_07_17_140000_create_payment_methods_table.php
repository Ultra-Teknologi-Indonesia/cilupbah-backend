<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code');           
            $table->string('name');
            $table->string('source_channel', 32)->nullable(); 
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['code', 'source_channel'], 'payment_methods_code_channel_unique');
            $table->index('is_active');
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->uuid('payment_method_id')->nullable()->after('payment_method_name');
            $table->foreign('payment_method_id')
                ->references('id')->on('payment_methods')
                ->nullOnDelete();
            $table->index('payment_method_id');
        });

        DB::statement(<<<'SQL'
            INSERT INTO payment_methods (id, code, name, source_channel, is_active, created_at, updated_at)
            SELECT
                gen_random_uuid(),
                so.payment_method,
                COALESCE(so.payment_method_name, so.payment_method),
                LOWER(so.source),
                true,
                NOW(), NOW()
            FROM (
                SELECT DISTINCT payment_method, payment_method_name, source
                FROM sales_orders
                WHERE payment_method IS NOT NULL AND payment_method <> ''
            ) so
            ON CONFLICT (code, source_channel) DO NOTHING
        SQL);

        DB::statement(<<<'SQL'
            UPDATE sales_orders so
            SET    payment_method_id = pm.id
            FROM   payment_methods pm
            WHERE  so.payment_method_id IS NULL
              AND  so.payment_method = pm.code
              AND  (pm.source_channel IS NULL OR pm.source_channel = LOWER(so.source))
        SQL);
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropForeign(['payment_method_id']);
            $table->dropIndex(['payment_method_id']);
            $table->dropColumn('payment_method_id');
        });

        Schema::dropIfExists('payment_methods');
    }
};
