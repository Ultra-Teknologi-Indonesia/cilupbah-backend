<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Modules\Report\Jobs\RunExportJob;
use Modules\Report\Models\ExportJob;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class MonitorStockExportParityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->user = $this->createPrivilegedUser();
    }

    public function test_export_defaults_to_the_same_habis_mode_as_the_api(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/inventory/monitor/export/async', [
                'format' => 'xlsx',
                'tab' => 'stok-kosong',
            ])
            ->assertAccepted();

        $job = ExportJob::findOrFail($response->json('data.export_id'));

        $this->assertSame('habis', $job->params['mode']);
        $this->assertArrayHasKey('allowed_location_ids', $job->params);
        Queue::assertPushed(RunExportJob::class);
    }

    public function test_minus_export_uses_the_same_small_warehouse_scope_as_the_api(): void
    {
        $smallWarehouse = Location::query()
            ->where('is_small_warehouse', true)
            ->where('is_warehouse', true)
            ->where('is_active', true)
            ->first();

        if (! $smallWarehouse) {
            $smallWarehouse = Location::create([
                'location_code' => 'WH-EXPORT-SMALL',
                'location_name' => 'Gudang Kecil Export Test',
                'location_type' => 'warehouse',
                'is_warehouse' => true,
                'is_small_warehouse' => true,
                'is_active' => true,
            ]);
        }

        $officialSmallWarehouseId = Location::getOfficialSmallWarehouseId();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/inventory/monitor/export/async', [
                'format' => 'xlsx',
                'tab' => 'stok-kosong',
                'mode' => 'minus',
            ])
            ->assertAccepted();

        $job = ExportJob::findOrFail($response->json('data.export_id'));

        $this->assertSame('minus', $job->params['mode']);
        $this->assertSame($officialSmallWarehouseId, $job->params['location_id']);
        $this->assertSame($smallWarehouse->id, $officialSmallWarehouseId);
        Queue::assertPushed(RunExportJob::class);
    }
}
