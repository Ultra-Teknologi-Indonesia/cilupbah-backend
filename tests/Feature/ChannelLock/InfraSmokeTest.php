<?php

namespace Tests\Feature\ChannelLock;

use App\Enums\ClientChannelEnum;
use App\Enums\UnassignReasonEnum;
use App\Exceptions\AssignmentLockException;
use App\Exceptions\AssignmentTakenOverException;
use App\Exceptions\PutawayActiveException;
use App\Exceptions\StaleWriteException;
use PHPUnit\Framework\TestCase;

class InfraSmokeTest extends TestCase
{
    public function test_assignment_lock_exception_carries_409_and_payload(): void
    {
        $ex = new AssignmentLockException('Andi', '01H001', '2026-07-14 10:00:00');
        $this->assertSame(409, $ex->getStatus());
        $this->assertStringContainsString('Andi', $ex->getMessage());
        $this->assertSame('ASSIGNMENT_LOCKED', $ex->getErrors()['code']);
    }

    public function test_assignment_taken_over_exception_carries_409_and_code(): void
    {
        $ex = new AssignmentTakenOverException('Budi', 'Kepala Gudang', '2026-07-14 11:00:00');
        $this->assertSame(409, $ex->getStatus());
        $this->assertSame('ASSIGNMENT_TAKEN_OVER', $ex->getErrors()['code']);
    }

    public function test_stale_write_exception_carries_412(): void
    {
        $ex = new StaleWriteException('Cici', '2026-07-14 12:00:00');
        $this->assertSame(412, $ex->getStatus());
        $this->assertSame('STALE_WRITE', $ex->getErrors()['code']);
    }

    public function test_putaway_active_exception_carries_422(): void
    {
        $ex = new PutawayActiveException(['PA-001', 'PA-002']);
        $this->assertSame(422, $ex->getStatus());
        $this->assertSame('PUTAWAY_ACTIVE', $ex->getErrors()['code']);
        $this->assertSame(['PA-001', 'PA-002'], $ex->getErrors()['active_putaways']);
    }

    public function test_client_channel_enum_values(): void
    {
        $this->assertSame('WEB', ClientChannelEnum::WEB->value);
        $this->assertSame('MOBILE', ClientChannelEnum::MOBILE->value);
    }

    public function test_unassign_reason_user_selectable_excludes_force_reset(): void
    {
        $selectable = UnassignReasonEnum::userSelectable();
        $this->assertNotContains(UnassignReasonEnum::FORCE_RESET, $selectable);
        $this->assertContains(UnassignReasonEnum::SALAH_TAP, $selectable);
        $this->assertContains(UnassignReasonEnum::LAINNYA, $selectable);
    }
}
