<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Report\Models\ExportJob;
use Tests\TestCase;

final class ExportDownloadCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_list_only_owned_exports_with_catalog_metadata(): void
    {
        $user = $this->createPrivilegedUser();
        $other = User::factory()->create();

        ExportJob::create([
            'user_id' => $user->id,
            'type' => 'inventory-stock',
            'status' => ExportJob::STATUS_READY,
            'file_name' => 'persediaan.xlsx',
            'file_size' => 2048,
            'file_disk' => 'documents',
            'file_path' => 'exports/owned.xlsx',
            'finished_at' => now(),
        ]);
        ExportJob::create([
            'user_id' => $other->id,
            'type' => 'sales-list',
            'status' => ExportJob::STATUS_READY,
            'file_name' => 'other.xlsx',
            'finished_at' => now(),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/exports?format=xlsx&per_page=20');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.type', 'inventory-stock')
            ->assertJsonPath('data.0.label', 'Persediaan Barang')
            ->assertJsonPath('data.0.category', 'inventory')
            ->assertJsonPath('data.0.format', 'xlsx')
            ->assertJsonPath('data.0.file_size', 2048)
            ->assertJsonPath('data.0.status', 'ready');
    }

    public function test_purged_file_is_expired_and_download_returns_gone(): void
    {
        Storage::fake('documents');
        $user = $this->createPrivilegedUser();
        $job = ExportJob::create([
            'user_id' => $user->id,
            'type' => 'inventory-stock',
            'status' => ExportJob::STATUS_READY,
            'file_disk' => 'documents',
            'file_path' => null,
            'file_name' => 'expired.xlsx',
            'file_purged_at' => now(),
            'finished_at' => now()->subDays(8),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/exports?status=expired')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'expired')
            ->assertJsonPath('data.0.file_available', false);

        $this->actingAs($user, 'sanctum')
            ->get('/api/v1/reports/exports/'.$job->id.'/download')
            ->assertStatus(410);
    }
}
