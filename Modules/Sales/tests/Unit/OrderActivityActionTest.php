<?php

namespace Modules\Sales\Tests\Unit;

use Modules\Sales\Enums\OrderActivityAction;
use PHPUnit\Framework\TestCase;

class OrderActivityActionTest extends TestCase
{
    public function test_every_action_has_unique_code(): void
    {
        $codes = array_map(fn ($case) => $case->code(), OrderActivityAction::cases());
        $this->assertCount(count($codes), array_unique($codes), 'Kode action_id harus unik antar enum case');
    }

    public function test_from_code_round_trip(): void
    {
        foreach (OrderActivityAction::cases() as $case) {
            $this->assertSame($case, OrderActivityAction::fromCode($case->code()));
        }
    }

    public function test_from_code_unknown_returns_null(): void
    {
        $this->assertNull(OrderActivityAction::fromCode('999X'));
    }
}
