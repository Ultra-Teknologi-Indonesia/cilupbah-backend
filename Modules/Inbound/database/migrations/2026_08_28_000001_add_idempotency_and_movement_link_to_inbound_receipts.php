<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** PostgreSQL concurrent indexes cannot run inside a transaction. */
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('inbound_receipts', function (Blueprint $table) {
            $table->string('idempotency_key', 100)->nullable()->after('inbound_item_id');
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->uuid('inbound_receipt_id')->nullable()->after('bin_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX CONCURRENTLY uq_inbound_receipts_item_idempotency
                ON inbound_receipts (inbound_item_id, idempotency_key)
                WHERE idempotency_key IS NOT NULL
            SQL);
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX CONCURRENTLY uq_inventory_movements_inbound_receipt
                ON inventory_movements (inbound_receipt_id)
                WHERE inbound_receipt_id IS NOT NULL
            SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE inventory_movements
                ADD CONSTRAINT fk_inventory_movements_inbound_receipt
                FOREIGN KEY (inbound_receipt_id)
                REFERENCES inbound_receipts (id)
                ON DELETE RESTRICT
                NOT VALID
            SQL);
            DB::statement('ALTER TABLE inventory_movements VALIDATE CONSTRAINT fk_inventory_movements_inbound_receipt');

            return;
        }

        Schema::table('inbound_receipts', function (Blueprint $table) {
            $table->unique(['inbound_item_id', 'idempotency_key'], 'uq_inbound_receipts_item_idempotency');
        });
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreign('inbound_receipt_id', 'fk_inventory_movements_inbound_receipt')
                ->references('id')
                ->on('inbound_receipts')
                ->restrictOnDelete();
            $table->unique('inbound_receipt_id', 'uq_inventory_movements_inbound_receipt');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE inventory_movements DROP CONSTRAINT IF EXISTS fk_inventory_movements_inbound_receipt');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS uq_inventory_movements_inbound_receipt');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS uq_inbound_receipts_item_idempotency');
        } else {
            Schema::table('inventory_movements', function (Blueprint $table) {
                $table->dropUnique('uq_inventory_movements_inbound_receipt');
                $table->dropForeign('fk_inventory_movements_inbound_receipt');
            });
            Schema::table('inbound_receipts', function (Blueprint $table) {
                $table->dropUnique('uq_inbound_receipts_item_idempotency');
            });
        }

        Schema::table('inventory_movements', fn (Blueprint $table) => $table->dropColumn('inbound_receipt_id'));
        Schema::table('inbound_receipts', function (Blueprint $table) {
            $table->dropColumn('idempotency_key');
        });
    }
};
