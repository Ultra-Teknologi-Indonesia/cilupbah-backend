<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class StockAdjustmentSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_adjustment_list_search_matches_document_notes(): void
    {
        $user = $this->createPrivilegedUser();
        $location = Location::create([
            'location_code' => 'WH-SEARCH',
            'location_name' => 'Gudang Search',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_active' => true,
        ]);

        StockAdjustment::create([
            'adjustment_no' => 'ADJ-SEARCH-001',
            'transaction_date' => now(),
            'location_id' => $location->id,
            'notes' => 'Koreksi stok rusak hasil stock opname',
            'created_by' => 'tester',
        ]);
        StockAdjustment::create([
            'adjustment_no' => 'ADJ-SEARCH-002',
            'transaction_date' => now(),
            'location_id' => $location->id,
            'notes' => 'Penyesuaian selisih penerimaan',
            'created_by' => 'tester',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/inventory/adjustments/documents?search=RUSAK&per_page=20');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.adjustment_no', 'ADJ-SEARCH-001')
            ->assertJsonPath('data.0.notes', 'Koreksi stok rusak hasil stock opname');
    }
}
