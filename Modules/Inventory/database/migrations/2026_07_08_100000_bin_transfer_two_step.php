<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bin_transfers', function (Blueprint $table) {
            $table->string('status', 20)->default('BARU_DIBUAT')->after('location_id');
            $table->timestamp('printed_at')->nullable()->after('notes');
            $table->string('printed_by', 100)->nullable()->after('printed_at');
            $table->index('status');
        });

        Schema::table('bin_transfer_items', function (Blueprint $table) {
            $table->integer('placed_qty')->default(0)->after('qty');
        });

        DB::statement('ALTER TABLE bin_transfer_items ALTER COLUMN destination_bin_id DROP NOT NULL');

        Schema::create('bin_transfer_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('receipt_number', 30)->unique();
            $table->foreignUuid('bin_transfer_id')->constrained('bin_transfers')->cascadeOnDelete();
            $table->foreignUuid('location_id')->constrained('locations')->restrictOnDelete();
            $table->string('received_by', 100);
            $table->timestamp('received_at');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('bin_transfer_id');
            $table->index('received_at');
        });

        Schema::create('bin_transfer_receipt_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bin_transfer_receipt_id')->constrained('bin_transfer_receipts')->cascadeOnDelete();

            $table->foreignUuid('bin_transfer_item_id')->constrained('bin_transfer_items')->cascadeOnDelete();
            $table->foreignUuid('destination_bin_id')->constrained('location_bins')->restrictOnDelete();
            $table->integer('qty');
            $table->timestamps();

            $table->index('bin_transfer_receipt_id');
            $table->index('bin_transfer_item_id');
        });

        DB::table('bin_transfers')->update(['status' => 'SELESAI']);
        DB::statement('UPDATE bin_transfer_items SET placed_qty = qty');
    }

    public function down(): void
    {
        Schema::dropIfExists('bin_transfer_receipt_items');
        Schema::dropIfExists('bin_transfer_receipts');

        DB::statement('ALTER TABLE bin_transfer_items ALTER COLUMN destination_bin_id SET NOT NULL');

        Schema::table('bin_transfer_items', function (Blueprint $table) {
            $table->dropColumn('placed_qty');
        });

        Schema::table('bin_transfers', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'printed_at', 'printed_by']);
        });
    }
};
