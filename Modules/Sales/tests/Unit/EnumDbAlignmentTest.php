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

class EnumDbAlignmentTest extends TestCase
{
    public function test_putaway_status_matches_native_enum(): void
    {

        $expected = ['NOT_STARTED', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'];
        $actual = array_map(fn ($c) => $c->value, PutawayStatus::cases());
        $this->assertEqualsCanonicalizing($expected, $actual);
    }

    public function test_stock_adjustment_status_matches_native_enum(): void
    {

        $expected = ['DRAFT', 'APPROVED', 'CANCELLED'];
        $actual = array_map(fn ($c) => $c->value, StockAdjustmentStatus::cases());
        $this->assertEqualsCanonicalizing($expected, $actual);
    }

    public function test_stock_opname_status_matches_native_enum(): void
    {

        $expected = ['DRAFT', 'IN_PROGRESS', 'FINALIZED', 'CANCELLED'];
        $actual = array_map(fn ($c) => $c->value, StockOpnameStatus::cases());
        $this->assertEqualsCanonicalizing($expected, $actual);
    }

    public function test_reserved_stock_status_matches_native_enum(): void
    {

        $expected = ['ACTIVE', 'EXPIRED', 'CANCELLED'];
        $actual = array_map(fn ($c) => $c->value, ReservedStockStatus::cases());
        $this->assertEqualsCanonicalizing($expected, $actual);
    }

    public function test_order_activity_entity_matches_check_constraint(): void
    {

        $expected = ['ORDER', 'ITEM'];
        $actual = array_map(fn ($c) => $c->value, OrderActivityEntity::cases());
        $this->assertEqualsCanonicalizing($expected, $actual);
    }

    public function test_order_activity_action_matches_check_constraint(): void
    {

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
