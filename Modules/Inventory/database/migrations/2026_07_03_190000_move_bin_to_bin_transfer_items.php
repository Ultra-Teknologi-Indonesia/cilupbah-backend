<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bin_transfer_items', function (Blueprint $table) {
            $table->foreignUuid('source_bin_id')
                ->after('item_id')
                ->constrained('location_bins')
                ->restrictOnDelete();
            $table->foreignUuid('destination_bin_id')
                ->after('source_bin_id')
                ->constrained('location_bins')
                ->restrictOnDelete();
        });

        Schema::table('bin_transfers', function (Blueprint $table) {
            $table->dropForeign(['source_bin_id']);
            $table->dropForeign(['destination_bin_id']);
            $table->dropColumn(['source_bin_id', 'destination_bin_id']);
        });
    }

    public function down(): void
    {
        Schema::table('bin_transfers', function (Blueprint $table) {
            $table->foreignUuid('source_bin_id')
                ->nullable()
                ->constrained('location_bins')
                ->restrictOnDelete();
            $table->foreignUuid('destination_bin_id')
                ->nullable()
                ->constrained('location_bins')
                ->restrictOnDelete();
        });

        Schema::table('bin_transfer_items', function (Blueprint $table) {
            $table->dropForeign(['source_bin_id']);
            $table->dropForeign(['destination_bin_id']);
            $table->dropColumn(['source_bin_id', 'destination_bin_id']);
        });
    }
};
