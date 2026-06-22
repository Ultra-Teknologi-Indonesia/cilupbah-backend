<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transfers', function (Blueprint $table) {
            $table->string('approved_by', 100)->nullable()->after('created_by');
            $table->string('assigned_to', 100)->nullable()->after('approved_by');
            $table->timestamp('approved_at')->nullable()->after('shipped_at');
            $table->string('cancelled_by', 100)->nullable()->after('received_by');
            $table->text('cancel_reason')->nullable()->after('cancelled_by');
            $table->timestamp('cancelled_at')->nullable()->after('received_at');
        });

        Schema::table('inventory_transfer_items', function (Blueprint $table) {
            $table->integer('rejected_qty')->default(0)->after('received_qty');
            $table->string('condition', 30)->default('GOOD')->after('rejected_qty');
            $table->text('item_notes')->nullable()->after('condition');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transfers', function (Blueprint $table) {
            $table->dropColumn(['approved_by', 'assigned_to', 'approved_at', 'cancelled_by', 'cancel_reason', 'cancelled_at']);
        });

        Schema::table('inventory_transfer_items', function (Blueprint $table) {
            $table->dropColumn(['rejected_qty', 'condition', 'item_notes']);
        });
    }
};
