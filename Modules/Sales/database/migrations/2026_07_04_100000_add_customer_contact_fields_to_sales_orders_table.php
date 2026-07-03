<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->timestamp('contacted_at')->nullable()->after('cancel_dismissed_by');
            $table->string('contacted_by', 36)->nullable()->after('contacted_at');
            $table->string('contact_channel', 30)->nullable()->after('contacted_by');
            $table->string('customer_decision', 20)->nullable()->after('contact_channel');
            $table->timestamp('decision_at')->nullable()->after('customer_decision');
            $table->string('decision_by', 36)->nullable()->after('decision_at');
            $table->string('contact_note', 500)->nullable()->after('decision_by');

            $table->index('contacted_at', 'idx_so_contacted_at');
            $table->index('customer_decision', 'idx_so_customer_decision');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropIndex('idx_so_contacted_at');
            $table->dropIndex('idx_so_customer_decision');
            $table->dropColumn([
                'contacted_at',
                'contacted_by',
                'contact_channel',
                'customer_decision',
                'decision_at',
                'decision_by',
                'contact_note',
            ]);
        });
    }
};
