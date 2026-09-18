<?php

declare(strict_types=1);

namespace Modules\Inventory\Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Modules\Report\Jobs\RunExportJob;
use Tests\TestCase;

final class StockPositionExportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'export-laporan-persediaan', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'stock-position-exporter', 'guard_name' => 'web']);
        $role->givePermissionTo('export-laporan-persediaan');

        $this->user = User::factory()->create();
        $this->user->assignRole($role);
    }

    public function test_csv_export_is_queued_with_visible_filters(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/inventory/stock-position/export/async', [
                'format' => 'csv',
                'search' => 'CASE',
                'sort' => '-available',
                'is_bundle' => '0',
                'channel' => 'shopee',
                'visible_location_ids' => [],
            ]);

        $response->assertStatus(202)->assertJsonPath('data.status', 'queued');
        Queue::assertPushed(RunExportJob::class);
        $this->assertDatabaseHas('export_jobs', [
            'type' => 'stock-position-csv',
            'user_id' => $this->user->id,
        ]);
    }

    public function test_pdf_export_uses_the_pdf_worker(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/inventory/stock-position/export/async', [
                'format' => 'pdf',
                'visible_location_ids' => [],
            ]);

        $response->assertStatus(202)->assertJsonPath('data.status', 'queued');
        $this->assertDatabaseHas('export_jobs', [
            'type' => 'stock-position-pdf',
            'queue_name' => config('exports.pdf_queue'),
        ]);
    }

    public function test_export_rejects_invalid_sort_and_missing_visible_locations(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/inventory/stock-position/export/async', [
                'format' => 'csv',
                'sort' => 'unsafe_column',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['sort', 'visible_location_ids']);
        Queue::assertNothingPushed();
    }
}
