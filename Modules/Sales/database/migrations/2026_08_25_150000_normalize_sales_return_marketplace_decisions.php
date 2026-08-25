<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_returns') || ! Schema::hasColumn('sales_returns', 'marketplace_decision')) {
            return;
        }

        DB::statement('ALTER TABLE sales_returns DROP CONSTRAINT IF EXISTS sales_returns_marketplace_decision_check');

        DB::statement(<<<'SQL'
            UPDATE sales_returns
            SET marketplace_decision = CASE
                WHEN UPPER(COALESCE(marketplace_raw_status, '')) IN (
                    'RETURN_OR_REFUND_REQUEST_COMPLETE', 'REFUND_SUCCESS',
                    'REFUND_SUCCESSFUL', 'REFUNDED', 'CANCEL_SUCCESS',
                    'CANCEL_REFUND_ISSUED', 'RTW_REFUND_PENDING'
                ) THEN 'MP_REFUNDED'
                WHEN UPPER(COALESCE(marketplace_raw_status, '')) IN (
                    'ACCEPTED', 'AWAITING_BUYER_SHIP', 'BUYER_SHIPPED_ITEM',
                    'REQUEST_SUCCESS', 'REQUEST_REVIEW_COMPLETED',
                    'RMA_CREATED'
                ) THEN 'MP_APPROVED'
                WHEN UPPER(COALESCE(marketplace_raw_status, '')) IN (
                    'PENDING', 'REQUESTED', 'PROCESSING',
                    'RETURN_OR_REFUND_REQUEST_PENDING', 'PENDING_REQUEST_REVIEW',
                    'CANCEL_INIT', 'RTM_INIT', 'RTW_INIT', 'REFUND_INIT',
                    'ON-HOLD'
                ) THEN 'MP_PENDING'
                WHEN UPPER(COALESCE(marketplace_raw_status, '')) IN (
                    'REQUEST_REJECTED', 'RETURN_OR_REFUND_REQUEST_REJECT',
                    'REFUND_OR_RETURN_REQUEST_REJECT', 'RECEIVE_REJECTED',
                    'REJECT_RECEIVE_PACKAGE', 'REPLACEMENT_REQUEST_REJECT',
                    'REJECTED'
                ) THEN 'MP_REJECTED'
                WHEN UPPER(COALESCE(marketplace_raw_status, '')) = 'SELLER_DISPUTE'
                    THEN 'MP_DISPUTE'
                WHEN UPPER(COALESCE(marketplace_raw_status, '')) = 'JUDGING'
                    THEN 'MP_JUDGING'
                WHEN UPPER(COALESCE(marketplace_raw_status, '')) IN (
                    'RETURN_OR_REFUND_CANCEL', 'RETURN_OR_REFUND_REQUEST_CANCEL',
                    'REPLACEMENT_REQUEST_CANCEL', 'CANCELLED', 'CLOSED',
                    'RTM_CANCELED', 'RTW_CANCELED'
                ) THEN 'MP_CLOSED'
                WHEN marketplace_decision IN ('MP_PENDING', 'MP_APPROVED', 'MP_REJECTED',
                    'MP_DISPUTE', 'MP_JUDGING', 'MP_REFUNDED', 'MP_CLOSED', 'MP_NOT_RETURN')
                    THEN marketplace_decision
                WHEN marketplace_decision = 'PENDING' THEN 'MP_PENDING'
                WHEN marketplace_decision IN ('BUYER_WIN', 'SELLER_WIN', 'NO_RETURN_NEEDED',
                    'SELLER_REFUSE_RETURN', 'REFUNDED', 'CANCELLED') THEN CASE marketplace_decision
                        WHEN 'BUYER_WIN' THEN 'MP_APPROVED'
                        WHEN 'SELLER_WIN' THEN 'MP_REJECTED'
                        WHEN 'NO_RETURN_NEEDED' THEN 'MP_NOT_RETURN'
                        WHEN 'SELLER_REFUSE_RETURN' THEN 'MP_REJECTED'
                        WHEN 'REFUNDED' THEN 'MP_REFUNDED'
                        WHEN 'CANCELLED' THEN 'MP_CLOSED'
                    END
                WHEN marketplace_decision IS NULL OR marketplace_decision = '' THEN NULL
                ELSE 'MP_PENDING'
            END
            WHERE marketplace_decision IS NOT NULL OR marketplace_raw_status IS NOT NULL;
        SQL);

        DB::statement(<<<'SQL'
            UPDATE sales_returns
            SET marketplace_decision_at = COALESCE(marketplace_decision_at, updated_at, created_at)
            WHERE marketplace_decision IS NOT NULL
              AND marketplace_decision_at IS NULL;
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE sales_returns
            ADD CONSTRAINT sales_returns_marketplace_decision_check
            CHECK (marketplace_decision IS NULL OR marketplace_decision IN (
                'MP_PENDING', 'MP_APPROVED', 'MP_REJECTED', 'MP_DISPUTE',
                'MP_JUDGING', 'MP_REFUNDED', 'MP_CLOSED', 'MP_NOT_RETURN'
            ))
            NOT VALID
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sales_returns DROP CONSTRAINT IF EXISTS sales_returns_marketplace_decision_check');
    }
};
