<?php

namespace Modules\Inbound\Tests\Feature;

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inbound\Models\Inbound;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class InboundPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbound_pagination_is_stable_when_expected_dates_are_equal(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = $this->createPrivilegedUser();
        $this->actingAs($admin, 'sanctum');

        $location = Location::create([
            'location_code' => 'WH-PAG',
            'location_name' => 'Gudang Pagination',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_active' => true,
        ]);

        $timestamp = '2026-08-31 04:00:00';
        for ($sequence = 1; $sequence <= 21; $sequence++) {
            $id = sprintf('00000000-0000-0000-0000-%012d', $sequence);

            Inbound::create([
                'id' => $id,
                'location_id' => $location->id,
                'transaction_number' => sprintf('TRFI-PAG-%03d', $sequence),
                'reference_number' => sprintf('ROO-PAG-%03d', $sequence),
                'type' => Inbound::TYPE_TRANSIT_IN,
                'source_type' => 'transfer',
                'source_id' => null,
                'status' => Inbound::STATUS_RECEIVED,
                'expected_date' => $timestamp,
                'created_by' => 'admin',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }

        $pageOne = $this->getJson('/api/v1/inbounds?filter[type]=TRANSIT_IN&page=1&per_page=20&sort=-expected_date')
            ->assertOk()
            ->json('data');
        $pageTwoResponse = $this->getJson('/api/v1/inbounds?filter[type]=TRANSIT_IN&page=2&per_page=20&sort=-expected_date')
            ->assertOk();
        $pageTwo = $pageTwoResponse->json('data');
        $pageTwoRepeat = $this->getJson('/api/v1/inbounds?filter[type]=TRANSIT_IN&page=2&per_page=20&sort=-expected_date')
            ->assertOk()
            ->json('data');

        $pageOneTransactions = array_column($pageOne, 'transaction_number');
        $pageTwoTransactions = array_column($pageTwo, 'transaction_number');
        $pageTwoIds = array_column($pageTwo, 'id');
        $pageTwoRepeatIds = array_column($pageTwoRepeat, 'id');

        $this->assertCount(20, $pageOne);
        $this->assertCount(1, $pageTwo);
        $this->assertSame($pageTwoIds, $pageTwoRepeatIds);
        $this->assertNotContains('TRFI-PAG-001', $pageOneTransactions);
        $this->assertSame(['TRFI-PAG-001'], $pageTwoTransactions);
        $this->assertSame(21, $pageTwoResponse->json('meta.total'));
    }
}
