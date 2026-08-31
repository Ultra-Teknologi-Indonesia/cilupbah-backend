<?php

namespace Tests\Feature\Report;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Modules\Report\Models\ExportJob;
use Modules\Report\Services\ExportManager;
use Tests\TestCase;

class ExportJobEndToEndTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['exports.connection' => 'sync']);
        $this->user = $this->createPrivilegedUser();
        Storage::fake('documents');
        Storage::fake('local');
    }

    public function test_export_job_status_and_download_flow(): void
    {
        $exportManager = app(ExportManager::class);

        $job = $exportManager->queue($this->user, 'transfer', [
            'jenis' => 'masuk',
            'from' => '2026-08-01',
            'to' => '2026-08-24',
        ]);

        $this->assertDatabaseHas('export_jobs', [
            'id' => $job->id,
            'user_id' => $this->user->id,
        ]);

        $job->refresh();
        $this->assertSame(ExportJob::STATUS_READY, $job->status);
        $this->assertNotNull($job->file_path);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/reports/exports/{$job->id}");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'ready');
        $this->assertNotNull($response->json('data.download_url'));

        $downloadResponse = $this->actingAs($this->user, 'sanctum')
            ->get("/api/v1/reports/exports/{$job->id}/download");

        $downloadResponse->assertOk();

        $otherUser = $this->createPrivilegedUser();
        $forbiddenResponse = $this->actingAs($otherUser, 'sanctum')
            ->getJson("/api/v1/reports/exports/{$job->id}");

        $forbiddenResponse->assertStatus(403);
    }
}
