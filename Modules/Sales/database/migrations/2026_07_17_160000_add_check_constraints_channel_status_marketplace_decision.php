<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE sales_orders
            ADD CONSTRAINT sales_orders_channel_status_check
            CHECK (channel_status IS NULL OR channel_status IN (
                'UNPAID','READY_TO_SHIP','PROCESSED','SHIPPED',
                'TO_CONFIRM_RECEIVE','COMPLETED','CANCELLED',
                'RETURN_REQUESTED','RETURNED','IN_CANCEL','UNKNOWN'
            ))
            NOT VALID
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE sales_returns
            ADD CONSTRAINT sales_returns_marketplace_decision_check
            CHECK (marketplace_decision IS NULL OR marketplace_decision IN (
                'PENDING','SELLER_WIN','BUYER_WIN','NO_RETURN_NEEDED',
                'SELLER_REFUSE_RETURN','REFUNDED','CANCELLED'
            ))
            NOT VALID
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sales_orders DROP CONSTRAINT IF EXISTS sales_orders_channel_status_check');
        DB::statement('ALTER TABLE sales_returns DROP CONSTRAINT IF EXISTS sales_returns_marketplace_decision_check');
    }
};
