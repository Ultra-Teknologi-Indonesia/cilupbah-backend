<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_payments') || ! Schema::hasTable('payment_methods')) {
            return;
        }

        if (! Schema::hasColumn('purchase_payments', 'payment_method_id')) {
            Schema::table('purchase_payments', function (Blueprint $table) {
                $table->uuid('payment_method_id')->nullable()->after('payment_method');
                $table->foreign('payment_method_id')
                    ->references('id')->on('payment_methods')
                    ->nullOnDelete();
                $table->index('payment_method_id');
            });
        }

        DB::statement(<<<'SQL'
            INSERT INTO payment_methods (id, code, name, source_channel, is_active, created_at, updated_at)
            SELECT gen_random_uuid(), pp.payment_method, pp.payment_method, 'internal', true, NOW(), NOW()
            FROM (SELECT DISTINCT payment_method FROM purchase_payments
                  WHERE payment_method IS NOT NULL AND payment_method <> '') pp
            WHERE NOT EXISTS (
                SELECT 1 FROM payment_methods pm
                WHERE pm.code = pp.payment_method AND pm.source_channel = 'internal'
            )
        SQL);

        DB::statement(<<<'SQL'
            UPDATE purchase_payments pp
            SET    payment_method_id = pm.id
            FROM   payment_methods pm
            WHERE  pp.payment_method_id IS NULL
              AND  pp.payment_method = pm.code
              AND  pm.source_channel = 'internal'
        SQL);
    }

    public function down(): void
    {
        if (Schema::hasColumn('purchase_payments', 'payment_method_id')) {
            Schema::table('purchase_payments', function (Blueprint $table) {
                $table->dropForeign(['payment_method_id']);
                $table->dropColumn('payment_method_id');
            });
        }
    }
};
