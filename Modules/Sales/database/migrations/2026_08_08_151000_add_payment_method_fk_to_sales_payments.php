<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_payments') || ! Schema::hasTable('payment_methods')) {
            return;
        }

        if (! Schema::hasColumn('sales_payments', 'payment_method_id')) {
            Schema::table('sales_payments', function (Blueprint $table) {
                $table->uuid('payment_method_id')->nullable()->after('payment_method');
                $table->foreign('payment_method_id')
                    ->references('id')->on('payment_methods')
                    ->nullOnDelete();
                $table->index('payment_method_id');
            });
        }

        DB::statement(<<<'SQL'
            INSERT INTO payment_methods (id, code, name, source_channel, is_active, created_at, updated_at)
            SELECT gen_random_uuid(), sp.payment_method, sp.payment_method, 'internal', true, NOW(), NOW()
            FROM (SELECT DISTINCT payment_method FROM sales_payments
                  WHERE payment_method IS NOT NULL AND payment_method <> '') sp
            WHERE NOT EXISTS (
                SELECT 1 FROM payment_methods pm
                WHERE pm.code = sp.payment_method AND pm.source_channel = 'internal'
            )
        SQL);

        DB::statement(<<<'SQL'
            UPDATE sales_payments sp
            SET    payment_method_id = pm.id
            FROM   payment_methods pm
            WHERE  sp.payment_method_id IS NULL
              AND  sp.payment_method = pm.code
              AND  pm.source_channel = 'internal'
        SQL);
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales_payments', 'payment_method_id')) {
            Schema::table('sales_payments', function (Blueprint $table) {
                $table->dropForeign(['payment_method_id']);
                $table->dropColumn('payment_method_id');
            });
        }
    }
};
