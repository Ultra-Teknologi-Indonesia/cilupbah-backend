<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->uuid('location_id')->nullable()->after('source');
            $table->string('cancel_request_reason')->nullable()->after('cancel_reason');
            $table->timestamp('cancel_requested_at')->nullable()->after('cancel_request_reason');
            $table->string('cancel_requested_by')->nullable()->after('cancel_requested_at');

            $table->foreign('location_id')->references('id')->on('locations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropColumn(['location_id', 'cancel_request_reason', 'cancel_requested_at', 'cancel_requested_by']);
        });
    }
};
