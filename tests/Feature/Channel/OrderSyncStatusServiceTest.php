<?php

namespace Tests\Feature\Channel;

use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\OrderSyncStatusService;
use Tests\TestCase;

class OrderSyncStatusServiceTest extends TestCase
{
    private function derive(array $attrs): string
    {
        $shop = new ChannelShop(array_merge([
            'access_token' => 'tok',
            'refresh_token_expires_at' => now()->addDays(30),
            'is_active' => true,
            'order_sync_enabled' => true,
        ], $attrs));

        return (new OrderSyncStatusService)->derive($shop)['status'];
    }

    public function test_normal_when_healthy_and_synced(): void
    {
        $this->assertSame(
            ChannelShop::ORDER_SYNC_NORMAL,
            $this->derive(['last_order_synced_at' => now()->subMinutes(5)]),
        );
    }

    public function test_pending_when_never_synced(): void
    {
        $this->assertSame(
            ChannelShop::ORDER_SYNC_PENDING,
            $this->derive(['last_order_synced_at' => null]),
        );
    }

    public function test_inactive_when_disabled(): void
    {
        $this->assertSame(
            ChannelShop::ORDER_SYNC_INACTIVE,
            $this->derive(['order_sync_enabled' => false, 'last_order_synced_at' => now()]),
        );
    }

    public function test_problem_when_integration_error(): void
    {
        $this->assertSame(
            ChannelShop::ORDER_SYNC_PROBLEM,
            $this->derive(['integration_status' => 'error', 'last_order_synced_at' => now()]),
        );
    }

    public function test_problem_when_disconnected(): void
    {
        $this->assertSame(
            ChannelShop::ORDER_SYNC_PROBLEM,
            $this->derive(['disconnected_at' => now(), 'last_order_synced_at' => now()]),
        );
    }

    public function test_problem_when_reauth_needed(): void
    {
        $this->assertSame(
            ChannelShop::ORDER_SYNC_PROBLEM,
            $this->derive(['access_token' => null, 'last_order_synced_at' => now()]),
        );
    }

    public function test_problem_when_pull_error_after_last_success(): void
    {
        $this->assertSame(
            ChannelShop::ORDER_SYNC_PROBLEM,
            $this->derive([
                'last_order_synced_at' => now()->subHour(),
                'last_order_error' => 'timeout',
                'last_order_error_at' => now()->subMinutes(2),
            ]),
        );
    }

    public function test_normal_when_pull_error_resolved_by_later_success(): void
    {
        $this->assertSame(
            ChannelShop::ORDER_SYNC_NORMAL,
            $this->derive([
                'last_order_error' => 'timeout',
                'last_order_error_at' => now()->subHour(),
                'last_order_synced_at' => now()->subMinutes(2),
            ]),
        );
    }
}
