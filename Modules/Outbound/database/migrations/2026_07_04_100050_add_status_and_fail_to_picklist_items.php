<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('picklist_items', function (Blueprint $table) {
            $table->string('item_status', 32)->nullable()->after('qty_picked');
            $table->string('fail_reason_code', 32)->nullable()->after('item_status');
            $table->text('fail_reason_note')->nullable()->after('fail_reason_code');
            $table->unsignedInteger('failed_qty')->nullable()->after('fail_reason_note');
            $table->dateTime('failed_at')->nullable()->after('failed_qty');
            $table->uuid('failed_by')->nullable()->after('failed_at');

            $table->foreign('failed_by')->references('id')->on('users')->nullOnDelete();
            $table->index('item_status');
        });
    }

    public function down(): void
    {
        Schema::table('picklist_items', function (Blueprint $table) {
            $table->dropForeign(['failed_by']);
            $table->dropIndex(['item_status']);
            $table->dropColumn([
                'item_status',
                'fail_reason_code',
                'fail_reason_note',
                'failed_qty',
                'failed_at',
                'failed_by',
            ]);
        });
    }
};
