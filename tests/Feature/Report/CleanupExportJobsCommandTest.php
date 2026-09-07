<?php

namespace Tests\Feature\Report;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Report\Models\ExportJob;
use Tests\TestCase;

class CleanupExportJobsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_purges_expired_export_file_without_deleting_history(): void
    {
        Storage::fake('documents');
        config()->set('file-retention.export_hours', 24);

        $user = User::factory()->create();
        $expired = ExportJob::create([
            'user_id' => $user->id,
            'type' => 'inventory-stock',
            'status' => ExportJob::STATUS_READY,
            'file_disk' => 'documents',
            'file_path' => 'exports/expired.xlsx',
            'file_name' => 'expired.xlsx',
            'finished_at' => now()->subHours(25),
        ]);
        $fresh = ExportJob::create([
            'user_id' => $user->id,
            'type' => 'inventory-stock',
            'status' => ExportJob::STATUS_READY,
            'file_disk' => 'documents',
            'file_path' => 'exports/fresh.xlsx',
            'file_name' => 'fresh.xlsx',
            'finished_at' => now()->subHours(23),
        ]);

        Storage::disk('documents')->put('exports/expired.xlsx', 'expired');
        Storage::disk('documents')->put('exports/fresh.xlsx', 'fresh');

        $this->artisan('reports:cleanup-export-jobs')
            ->assertSuccessful();

        Storage::disk('documents')->assertMissing('exports/expired.xlsx');
        Storage::disk('documents')->assertExists('exports/fresh.xlsx');
        $this->assertDatabaseHas('export_jobs', [
            'id' => $expired->id,
            'file_path' => null,
        ]);
        $this->assertNotNull($expired->fresh()->file_purged_at);
        $this->assertDatabaseHas('export_jobs', [
            'id' => $fresh->id,
            'file_path' => 'exports/fresh.xlsx',
        ]);
    }
}
