<?php

declare(strict_types=1);

namespace Modules\Outbound\Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Outbound\Jobs\RunProcessOrdersCsvExportJob;
use Modules\Outbound\Services\ProcessOrdersCsvExportService;
use Modules\Report\Models\ExportJob;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

final class ProcessOrdersCsvExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_process_orders_export_is_queued_for_users_with_permission(): void
    {
        Queue::fake();
        $user = $this->exportUser();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/outbound/orders/export/async');

        $response->assertStatus(202)
            ->assertJsonPath('data.status', ExportJob::STATUS_QUEUED);

        Queue::assertPushed(RunProcessOrdersCsvExportJob::class);
        $this->assertDatabaseHas('export_jobs', [
            'user_id' => $user->id,
            'type' => 'outbound-orders-csv',
            'status' => ExportJob::STATUS_QUEUED,
        ]);
    }

    public function test_process_orders_export_requires_export_permission(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/outbound/orders/export/async')
            ->assertForbidden();

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('export_jobs', 0);
    }

    public function test_csv_is_utf8_and_contains_process_status_without_loading_all_rows(): void
    {
        SalesOrder::factory()->create([
            'status' => 'reserved',
            'handed_to_warehouse_at' => now(),
            'customer_name' => '=HYPERLINK("https://example.test")',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'process-orders-test-');
        $this->assertNotFalse($path);

        try {
            $count = app(ProcessOrdersCsvExportService::class)->write($path);
            $csv = file_get_contents($path);

            $this->assertSame(1, $count);
            $this->assertIsString($csv);
            $this->assertStringStartsWith("\xEF\xBB\xBF\"No. Pesanan\"", $csv);
            $this->assertStringContainsString('Picking', $csv);
            $this->assertStringContainsString("'=HYPERLINK", $csv);
        } finally {
            @unlink($path);
        }
    }

    public function test_worker_writes_csv_to_private_export_storage(): void
    {
        Storage::fake('documents');
        $user = $this->exportUser();
        SalesOrder::factory()->create([
            'status' => 'shipped',
            'received_date' => null,
        ]);

        $exportJob = ExportJob::create([
            'user_id' => $user->id,
            'type' => 'outbound-orders-csv',
            'params' => ['scope' => 'all-process-statuses'],
            'status' => ExportJob::STATUS_QUEUED,
        ]);

        (new RunProcessOrdersCsvExportJob($exportJob->id))
            ->handle(app(ProcessOrdersCsvExportService::class));

        $exportJob->refresh();

        $this->assertSame(ExportJob::STATUS_READY, $exportJob->status);
        $this->assertSame('documents', $exportJob->file_disk);
        $this->assertStringEndsWith('.csv', $exportJob->file_name);
        Storage::disk('documents')->assertExists($exportJob->file_path);
    }

    private function exportUser(): User
    {
        Permission::firstOrCreate(['name' => 'export-pesanan', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'process-order-exporter', 'guard_name' => 'web']);
        $role->givePermissionTo('export-pesanan');

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }
}
