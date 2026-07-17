<?php

namespace Modules\Sales\Tests\Unit;

use Modules\Sales\Enums\SalesOrderStatus;
use PHPUnit\Framework\TestCase;

class SalesOrderStatusTransitionTest extends TestCase
{
    public function test_happy_path_transitions(): void
    {
        $this->assertTrue(SalesOrderStatus::PENDING->canTransitionTo(SalesOrderStatus::RESERVED));
        $this->assertTrue(SalesOrderStatus::RESERVED->canTransitionTo(SalesOrderStatus::PICKED));
        $this->assertTrue(SalesOrderStatus::PICKED->canTransitionTo(SalesOrderStatus::PACKED));
        $this->assertTrue(SalesOrderStatus::PACKED->canTransitionTo(SalesOrderStatus::SHIPPED));
    }

    public function test_cancel_allowed_pre_shipped(): void
    {
        foreach ([SalesOrderStatus::PENDING, SalesOrderStatus::RESERVED, SalesOrderStatus::PICKED, SalesOrderStatus::PACKED] as $s) {
            $this->assertTrue(
                $s->canTransitionTo(SalesOrderStatus::CANCELLED),
                "{$s->value} harus bisa transition ke cancelled",
            );
        }
    }

    public function test_terminal_states_lock(): void
    {
        foreach (SalesOrderStatus::cases() as $target) {
            $this->assertFalse(
                SalesOrderStatus::SHIPPED->canTransitionTo($target),
                'shipped tidak boleh transit ke apa pun',
            );
            $this->assertFalse(
                SalesOrderStatus::CANCELLED->canTransitionTo($target),
                'cancelled tidak boleh transit ke apa pun',
            );
        }
    }

    public function test_illegal_skip(): void
    {
        $this->assertFalse(SalesOrderStatus::PENDING->canTransitionTo(SalesOrderStatus::SHIPPED));
        $this->assertFalse(SalesOrderStatus::RESERVED->canTransitionTo(SalesOrderStatus::PACKED));
    }
}
