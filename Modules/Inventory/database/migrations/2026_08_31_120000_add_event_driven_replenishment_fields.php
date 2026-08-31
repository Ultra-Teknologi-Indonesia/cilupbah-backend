<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->extendStatusVocabulary();

        Schema::table('stock_replenishment_requests', function (Blueprint $table): void {
            $table->string('source', 20)->default('MANUAL')->after('status');
            $table->string('batch_key', 160)->nullable()->after('source');
            $table->timestamp('last_reconciled_at')->nullable()->after('done_at');
            $table->timestamp('cancelled_at')->nullable()->after('last_reconciled_at');
            $table->string('cancel_reason', 500)->nullable()->after('reject_reason');

            $table->index(
                ['status', 'from_location_id', 'to_location_id'],
                'idx_srr_active_route',
            );
            $table->index('batch_key', 'idx_srr_batch_key');
        });

        Schema::table('stock_replenishment_request_items', function (Blueprint $table): void {
            $table->integer('demand_qty')->default(0)->after('qty');
            $table->integer('available_qty')->default(0)->after('demand_qty');
            $table->integer('in_flight_qty')->default(0)->after('available_qty');
            $table->integer('suggested_qty')->default(0)->after('in_flight_qty');

            $table->index(
                ['request_id', 'item_id'],
                'idx_srri_request_item',
            );
        });
    }

    public function down(): void
    {
        Schema::table('stock_replenishment_request_items', function (Blueprint $table): void {
            $table->dropIndex('idx_srri_request_item');
            $table->dropColumn([
                'demand_qty',
                'available_qty',
                'in_flight_qty',
                'suggested_qty',
            ]);
        });

        Schema::table('stock_replenishment_requests', function (Blueprint $table): void {
            $table->dropIndex('idx_srr_active_route');
            $table->dropIndex('idx_srr_batch_key');
            $table->dropColumn([
                'source',
                'batch_key',
                'last_reconciled_at',
                'cancelled_at',
                'cancel_reason',
            ]);
        });

        $this->restoreStatusVocabulary();
    }

    private function extendStatusVocabulary(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE stock_replenishment_requests DROP CONSTRAINT IF EXISTS stock_replenishment_requests_status_check');
            DB::statement("ALTER TABLE stock_replenishment_requests ADD CONSTRAINT stock_replenishment_requests_status_check CHECK (status IN ('PENDING', 'ACCEPTED', 'REJECTED', 'DONE', 'CANCELLED'))");
        } elseif ($driver === 'mysql') {
            DB::statement("ALTER TABLE stock_replenishment_requests MODIFY status ENUM('PENDING', 'ACCEPTED', 'REJECTED', 'DONE', 'CANCELLED') NOT NULL DEFAULT 'PENDING'");
        }
    }

    private function restoreStatusVocabulary(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE stock_replenishment_requests DROP CONSTRAINT IF EXISTS stock_replenishment_requests_status_check');
            DB::statement("ALTER TABLE stock_replenishment_requests ADD CONSTRAINT stock_replenishment_requests_status_check CHECK (status IN ('PENDING', 'ACCEPTED', 'REJECTED', 'DONE'))");
        } elseif ($driver === 'mysql') {
            DB::statement("ALTER TABLE stock_replenishment_requests MODIFY status ENUM('PENDING', 'ACCEPTED', 'REJECTED', 'DONE') NOT NULL DEFAULT 'PENDING'");
        }
    }
};
