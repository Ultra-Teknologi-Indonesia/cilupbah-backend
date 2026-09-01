<?php

namespace Modules\Inbound\Tests\Feature;

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inbound\Models\Inbound;
use Modules\Inbound\Models\InboundItem;
use Modules\Inventory\Models\Putaway;
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

    public function test_receiving_list_uses_meaningful_linked_putaway_note_without_exposing_generated_note(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = $this->createPrivilegedUser();
        $this->actingAs($admin, 'sanctum');

        $location = Location::create([
            'location_code' => 'WH-NOTE',
            'location_name' => 'Gudang Notes',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_active' => true,
        ]);

        $inbound = Inbound::create([
            'location_id' => $location->id,
            'transaction_number' => 'INB-NOTE-001',
            'reference_number' => 'PO-NOTE-001',
            'type' => Inbound::TYPE_PURCHASE_ORDER,
            'source_type' => 'purchase_order',
            'status' => Inbound::STATUS_RECEIVED,
            'expected_date' => now()->toDateString(),
            'created_by' => 'admin',
        ]);

        InboundItem::create([
            'inbound_id' => $inbound->id,
            'item_id' => Str::uuid()->toString(),
            'expected_qty' => 10,
            'received_qty' => 10,
            'rejected_qty' => 0,
            'putaway_qty' => 0,
        ]);

        $putaway = Putaway::create([
            'putaway_no' => 'PUT-NOTE-001',
            'location_id' => $location->id,
            'source_type' => 'INBOUND',
            'source_id' => $inbound->id,
            'status' => Putaway::STATUS_NOT_STARTED,
            'notes' => 'reject 191pcs',
            'created_by' => 'admin',
        ]);

        $generatedPutaway = Putaway::create([
            'putaway_no' => 'PUT-NOTE-002',
            'location_id' => $location->id,
            'source_type' => 'INBOUND',
            'source_id' => null,
            'status' => Putaway::STATUS_NOT_STARTED,
            'notes' => 'Manual Putaway from Inbound INB-NOTE-001',
            'created_by' => 'admin',
        ]);

        DB::table('putaway_sources')->insert([
            'id' => Str::uuid()->toString(),
            'putaway_id' => $generatedPutaway->id,
            'inbound_id' => $inbound->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/inbounds?filter[type]=PURCHASE_ORDER&search=INB-NOTE-001&per_page=20')
            ->assertOk();

        $response->assertJsonPath('data.0.notes', 'reject 191pcs');
        $this->assertStringNotContainsString(
            'Manual Putaway from Inbound',
            (string) $response->json('data.0.notes'),
        );
    }

    public function test_receiving_list_uses_rejection_summary_and_ignores_cancelled_putaway_notes(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = $this->createPrivilegedUser();
        $this->actingAs($admin, 'sanctum');

        $location = Location::create([
            'location_code' => 'WH-REJECT',
            'location_name' => 'Gudang Reject',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_active' => true,
        ]);

        $inbound = Inbound::create([
            'location_id' => $location->id,
            'transaction_number' => 'INB-REJECT-001',
            'reference_number' => 'PO-REJECT-001',
            'type' => Inbound::TYPE_PURCHASE_ORDER,
            'source_type' => 'purchase_order',
            'status' => Inbound::STATUS_RECEIVED,
            'expected_date' => now()->toDateString(),
            'created_by' => 'admin',
        ]);

        InboundItem::create([
            'inbound_id' => $inbound->id,
            'item_id' => Str::uuid()->toString(),
            'expected_qty' => 10,
            'received_qty' => 6,
            'rejected_qty' => 4,
            'rejection_note' => 'kemasan rusak',
            'putaway_qty' => 0,
        ]);

        $putaway = Putaway::create([
            'putaway_no' => 'PUT-REJECT-001',
            'location_id' => $location->id,
            'source_type' => 'INBOUND',
            'source_id' => $inbound->id,
            'status' => Putaway::STATUS_CANCELLED,
            'notes' => 'catatan lama yang dibatalkan',
            'created_by' => 'admin',
        ]);

        DB::table('putaway_sources')->insert([
            'id' => Str::uuid()->toString(),
            'putaway_id' => $putaway->id,
            'inbound_id' => $inbound->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/inbounds?filter[type]=PURCHASE_ORDER&search=INB-REJECT-001&per_page=20')
            ->assertOk();

        $response->assertJsonPath('data.0.notes', 'reject 4pcs; kemasan rusak');
        $this->assertStringNotContainsString(
            'catatan lama yang dibatalkan',
            (string) $response->json('data.0.notes'),
        );
    }
}
