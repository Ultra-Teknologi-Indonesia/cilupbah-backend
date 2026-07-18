<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        if (Schema::hasColumn('sales_orders', 'wms_status')) {
            DB::statement(<<<'SQL'
                ALTER TABLE sales_orders
                ADD CONSTRAINT sales_orders_wms_status_check
                CHECK (wms_status IS NULL OR wms_status IN (
                    'OTHER','CREATED','PAID','PROCESS',
                    'PICK','FINISH_PICK','PACK','FINISH_PACK',
                    'READY_TO_SHIP','SHIPPED','COMPLETED',
                    'CANCELLED','FAILED','RETURNED'
                ))
                NOT VALID
            SQL);
        }

        if (Schema::hasColumn('sales_orders', 'source')) {
            DB::statement(<<<'SQL'
                ALTER TABLE sales_orders
                ADD CONSTRAINT sales_orders_source_check
                CHECK (source IS NULL OR source IN (
                    'shopee','tokopedia','tiktok','lazada',
                    'woocommerce','blibli','manual','pos'
                ))
                NOT VALID
            SQL);
        }

        if (Schema::hasColumn('sales_orders', 'contact_channel')) {
            DB::statement(<<<'SQL'
                ALTER TABLE sales_orders
                ADD CONSTRAINT sales_orders_contact_channel_check
                CHECK (contact_channel IS NULL OR contact_channel IN (
                    'marketplace_chat','whatsapp','phone','other'
                ))
                NOT VALID
            SQL);
        }

        if (Schema::hasColumn('sales_orders', 'customer_decision')) {
            DB::statement(<<<'SQL'
                ALTER TABLE sales_orders
                ADD CONSTRAINT sales_orders_customer_decision_check
                CHECK (customer_decision IS NULL OR customer_decision IN (
                    'waiting','cancel','replace'
                ))
                NOT VALID
            SQL);
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sales_orders DROP CONSTRAINT IF EXISTS sales_orders_wms_status_check');
        DB::statement('ALTER TABLE sales_orders DROP CONSTRAINT IF EXISTS sales_orders_source_check');
        DB::statement('ALTER TABLE sales_orders DROP CONSTRAINT IF EXISTS sales_orders_contact_channel_check');
        DB::statement('ALTER TABLE sales_orders DROP CONSTRAINT IF EXISTS sales_orders_customer_decision_check');
    }
};
