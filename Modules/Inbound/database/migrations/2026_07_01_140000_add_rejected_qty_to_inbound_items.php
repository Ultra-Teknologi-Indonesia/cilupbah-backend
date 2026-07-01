<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbound_items', function (Blueprint $table) {
            $table->integer('rejected_qty')->default(0)->after('received_qty');
            $table->text('rejection_note')->nullable()->after('rejected_qty');
        });
    }

    public function down(): void
    {
        Schema::table('inbound_items', function (Blueprint $table) {
            $table->dropColumn(['rejected_qty', 'rejection_note']);
        });
    }
};
