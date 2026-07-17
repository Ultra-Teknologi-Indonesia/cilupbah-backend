<?php

namespace Modules\Sales\Tests\Unit;

use Modules\Inventory\Enums\PutawayStatus;
use Modules\Inventory\Enums\ReservedStockStatus;
use Modules\Inventory\Enums\StockAdjustmentStatus;
use Modules\Inventory\Enums\StockOpnameStatus;
use Modules\Sales\Enums\ChannelStatus;
use Modules\Sales\Enums\DisputeOutcome;
use Modules\Sales\Enums\OrderActivityAction;
use Modules\Sales\Enums\OrderActivityEntity;
use PHPUnit\Framework\TestCase;

/**
 * Menjamin nilai enum PHP selaras dengan nilai yang dipakai di migrasi asli
 * (Postgres check constraint / default). Kalau enum diperluas tanpa update
 * DB (atau sebaliknya), test ini gagal — bukan runtime error di prod.
 */
class EnumDbAlignmentTest extends TestCase
{
    public function test_putaway_status_matches_native_enum(): void
    {
        // create_putaways_table.php: enum('status', ['NOT_STARTED','IN_PROGRESS','COMPLETED','CANCELLED'])
        $expected = ['NOT_STARTED', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'];
        $actual = array_map(fn ($c) => $c->value, PutawayStatus::cases());
        $this->assertEqualsCanonicalizing($expected, $actual);
    }

    public function test_stock_adjustment_status_matches_native_enum(): void
    {
        // create_stock_adjustments_table.php: enum('status', ['DRAFT','APPROVED','CANCELLED'])
        $expected = ['DRAFT', 'APPROVED', 'CANCELLED'];
        $actual = array_map(fn ($c) => $c->value, StockAdjustmentStatus::cases());
        $this->assertEqualsCanonicalizing($expected, $actual);
    }

    public function test_stock_opname_status_matches_native_enum(): void
    {
        // create_stock_opnames_table.php: enum('status', ['DRAFT','IN_PROGRESS','FINALIZED','CANCELLED'])
        $expected = ['DRAFT', 'IN_PROGRESS', 'FINALIZED', 'CANCELLED'];
        $actual = array_map(fn ($c) => $c->value, StockOpnameStatus::cases());
        $this->assertEqualsCanonicalizing($expected, $actual);
    }

    public function test_reserved_stock_status_matches_native_enum(): void
    {
        // create_reserved_stocks_table.php: enum('status', ['ACTIVE','EXPIRED','CANCELLED'])
        $expected = ['ACTIVE', 'EXPIRED', 'CANCELLED'];
        $actual = array_map(fn ($c) => $c->value, ReservedStockStatus::cases());
        $this->assertEqualsCanonicalizing($expected, $actual);
    }

    public function test_order_activity_entity_matches_check_constraint(): void
    {
        // 2026_07_17_110000_add_enum_constraints_to_sales_order_status_histories.php
        $expected = ['ORDER', 'ITEM'];
        $actual = array_map(fn ($c) => $c->value, OrderActivityEntity::cases());
        $this->assertEqualsCanonicalizing($expected, $actual);
    }

    public function test_order_activity_action_matches_check_constraint(): void
    {
        // Sama migrasi: 21 nilai case
        $expected = [
            'CREATED', 'PAID', 'PROCESS',
            'PICK_STARTED', 'PICK_FAILED', 'FINISH_PICK',
            'PACK_STARTED', 'FINISH_PACK', 'LABEL_PRINTED',
            'READY_TO_SHIP', 'DRIVER_CALLED',
            'TRACKING_UPDATED', 'CHANNEL_STATUS',
            'RECEIVED_BY_BUYER', 'RETURN_DECISION',
            'FIELD_CHANGED', 'SHIPPED', 'COMPLETED', 'CANCELLED',
            'ZONE_ASSIGNED', 'ITEM_CREATED',
        ];
        $actual = array_map(fn ($c) => $c->value, OrderActivityAction::cases());
        $this->assertEqualsCanonicalizing($expected, $actual);
    }

    public function test_channel_status_matches_check_constraint(): void
    {
        // 2026_07_17_160000_add_check_constraints_channel_status_marketplace_decision.php
        $expected = [
            'UNPAID', 'READY_TO_SHIP', 'PROCESSED', 'SHIPPED',
            'TO_CONFIRM_RECEIVE', 'COMPLETED', 'CANCELLED',
            'RETURN_REQUESTED', 'RETURNED', 'IN_CANCEL', 'UNKNOWN',
        ];
        $actual = array_map(fn ($c) => $c->value, ChannelStatus::cases());
        $this->assertEqualsCanonicalizing($expected, $actual);
    }

    public function test_dispute_outcome_matches_check_constraint(): void
    {
        $expected = [
            'PENDING', 'SELLER_WIN', 'BUYER_WIN', 'NO_RETURN_NEEDED',
            'SELLER_REFUSE_RETURN', 'REFUNDED', 'CANCELLED',
        ];
        $actual = array_map(fn ($c) => $c->value, DisputeOutcome::cases());
        $this->assertEqualsCanonicalizing($expected, $actual);
    }
}
